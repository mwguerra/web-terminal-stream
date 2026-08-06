<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use MWGuerra\WebTerminalStream\Tests\Benchmarks\Support\StressDriver;
use MWGuerra\WebTerminalStream\Tests\IntegrationTestCase;

uses(IntegrationTestCase::class);

const WTS_STRESS_HOST = '127.0.0.1';
const WTS_STRESS_SERVER_PORT = 8098;

/**
 * Boot `terminal-stream:serve` for the stress run — same shared APP_KEY +
 * file cache contract as the full-stack integration tests, with the
 * connection caps pinned so the over-cap probe measures a known limit.
 *
 * @return resource
 */
function bootStressServer(int $maxConnections): mixed
{
    $repoRoot = dirname(__DIR__, 2);

    $process = proc_open(
        [
            PHP_BINARY,
            $repoRoot.'/vendor/bin/testbench',
            'terminal-stream:serve',
            // Client-leg latency profiles need toxiproxy (a container) to
            // reach this server via host.docker.internal, so the runner
            // binds wide for the seconds the run lasts. Token-gated.
            '--host='.(getenv('WTS_STRESS_BIND') ?: WTS_STRESS_HOST),
            '--port='.WTS_STRESS_SERVER_PORT,
        ],
        [
            1 => ['file', '/dev/null', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ],
        $pipes,
        $repoRoot,
        array_merge(getenv() ?: [], [
            'APP_KEY' => IntegrationTestCase::APP_KEY,
            'CACHE_STORE' => 'file',
            'WEB_TERMINAL_STREAM_MAX_CONNECTIONS' => (string) $maxConnections,
        ]),
    );

    if (! is_resource($process)) {
        test()->fail('Failed to spawn the terminal-stream:serve process');
    }

    $deadline = microtime(true) + 15.0;
    while (microtime(true) < $deadline) {
        $probe = @fsockopen(WTS_STRESS_HOST, WTS_STRESS_SERVER_PORT, $errno, $errstr, 0.5);
        if ($probe !== false) {
            fclose($probe);

            return $process;
        }
        usleep(100_000);
    }

    proc_terminate($process, SIGKILL);
    proc_close($process);
    test()->fail('terminal-stream:serve did not start listening for the stress run');
}

/**
 * Mint one token-authenticated ws:// URL. Distinct userId per connection so
 * `max_sessions_per_user` never interferes with the `max_connections` probe.
 */
function mintStressUrl(int $userId, int $clientPort, int $ttl = 600): string
{
    $ssh = sshTestConfig();
    $sessionId = Str::uuid()->toString();

    Cache::put("terminal-stream-pty:{$sessionId}", app('encrypter')->encrypt([
        'type' => 'ssh',
        'host' => $ssh['host'],
        'username' => $ssh['username'],
        'password' => $ssh['password'],
        'port' => $ssh['port'],
        'timeout' => 10,
    ]), $ttl);

    $token = app('encrypter')->encrypt(json_encode([
        'userId' => $userId,
        'sessionId' => $sessionId,
        'exp' => time() + $ttl,
    ]));

    return 'ws://'.WTS_STRESS_HOST.':'.$clientPort.'/?token='.urlencode($token);
}

function stressRegistryCount(): int
{
    $path = storage_path('web-terminal-stream/pty-sessions.json');
    if (! file_exists($path)) {
        return 0;
    }

    $sessions = json_decode((string) file_get_contents($path), true);

    return is_array($sessions) ? count($sessions) : 0;
}

describe('connection stress', function () {
    it('holds the fleet, isolates round-trips, and enforces the connection cap', function () {
        if (! getenv('WTS_STRESS')) {
            $this->markTestSkipped('Stress run is opt-in: WTS_STRESS=1 (use composer stress)');
        }
        requireSshTarget();

        $profile = getenv('WTS_STRESS_PROFILE') ?: 'baseline';
        $fleet = (int) (getenv('WTS_STRESS_CONNECTIONS') ?: 100);
        $overcap = (int) (getenv('WTS_STRESS_OVERCAP') ?: 10);
        $maxLifetime = (int) (getenv('WTS_STRESS_MAX_LIFETIME') ?: 10);
        // Client-leg latency profiles connect through toxiproxy instead.
        $clientPort = (int) (getenv('WTS_STRESS_WS_PORT') ?: WTS_STRESS_SERVER_PORT);

        $server = bootStressServer($fleet);
        $registryBaseline = stressRegistryCount();

        try {
            $fleetUrls = [];
            for ($i = 0; $i < $fleet; $i++) {
                $fleetUrls[] = mintStressUrl($i + 1, $clientPort);
            }
            $overcapUrls = [];
            for ($i = 0; $i < $overcap; $i++) {
                $overcapUrls[] = mintStressUrl($fleet + $i + 1, $clientPort);
            }

            $deadline = getenv('WTS_STRESS_DEADLINE') ? (int) getenv('WTS_STRESS_DEADLINE') : null;
            $driver = new StressDriver($fleetUrls, $overcapUrls, $maxLifetime, $deadline);
            $summary = $driver->run($profile);

            if ($out = getenv('WTS_STRESS_OUT')) {
                @mkdir(dirname($out), 0755, true);
                file_put_contents($out, json_encode($summary, JSON_PRETTY_PRINT)."\n");
            }

            fwrite(STDOUT, sprintf(
                "\n[%s] established %d/%d, rtt p50=%sms p95=%sms (n=%d), overcap rejected %d/%d, duration %ss\n",
                $profile,
                $summary['established'],
                $fleet,
                $summary['rtt_ms']['p50'] ?? '—',
                $summary['rtt_ms']['p95'] ?? '—',
                $summary['rtt_samples'],
                $summary['overcap_rejected'],
                $overcap,
                $summary['duration_s'],
            ));

            expect($summary['established'])->toBe($fleet)
                ->and($summary['connect_failures'])->toBe(0)
                ->and($summary['clean_closes'])->toBe($fleet)
                ->and($summary['unexpected_closes'])->toBe(0)
                ->and($summary['overcap_accepted'])->toBe(0)
                ->and($summary['overcap_rejected'])->toBe($overcap)
                ->and($summary['rtt_samples'])->toBeGreaterThan(0);

            // Every PTY the run created must be reaped once its socket closed.
            $deadline = microtime(true) + 10.0;
            while (microtime(true) < $deadline && stressRegistryCount() > $registryBaseline) {
                usleep(200_000);
            }
            expect(stressRegistryCount())->toBeLessThanOrEqual($registryBaseline);
        } finally {
            $status = proc_get_status($server);
            if ($status['running'] && ($status['pid'] ?? 0) > 0) {
                posix_kill($status['pid'], SIGTERM);
            }
            proc_terminate($server, SIGTERM);
            proc_close($server);
        }
    });
})->group('stress');
