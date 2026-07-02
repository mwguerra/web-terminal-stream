<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use MWGuerra\WebTerminalStream\Tests\IntegrationTestCase;
use WebSocket\Client as WsClient;
use WebSocket\Exception\Exception as WsException;
use WebSocket\Message\Text as WsText;

const WTS_FULLSTACK_HOST = '127.0.0.1';
const WTS_FULLSTACK_PORT = 8099;

/**
 * Boot `terminal-stream:serve` on the Testbench skeleton as a separate
 * process sharing APP_KEY + the file cache store with this test app.
 *
 * @return resource
 */
function bootStreamServer(): mixed
{
    $repoRoot = dirname(__DIR__, 3);

    $process = proc_open(
        [
            PHP_BINARY,
            $repoRoot.'/vendor/bin/testbench',
            'terminal-stream:serve',
            '--host='.WTS_FULLSTACK_HOST,
            '--port='.WTS_FULLSTACK_PORT,
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
        ]),
    );

    if (! is_resource($process)) {
        test()->fail('Failed to spawn the terminal-stream:serve process');
    }

    // Wait until the server socket accepts connections.
    $deadline = microtime(true) + 15.0;
    while (microtime(true) < $deadline) {
        $probe = @fsockopen(WTS_FULLSTACK_HOST, WTS_FULLSTACK_PORT, $errno, $errstr, 0.5);
        if ($probe !== false) {
            fclose($probe);

            return $process;
        }
        usleep(100_000);
    }

    proc_terminate($process, SIGKILL);
    proc_close($process);
    test()->fail('terminal-stream:serve did not start listening on '.WTS_FULLSTACK_HOST.':'.WTS_FULLSTACK_PORT);
}

/**
 * Store the pane connection config in the shared cache and mint a token
 * exactly the way TerminalWebSocketController::generateToken() does.
 *
 * @return array{0: string, 1: string} [token, sessionId]
 */
function mintWsToken(int $ttl = 300): array
{
    $ssh = sshTestConfig();
    $sessionId = Str::uuid()->toString();

    Cache::put("terminal-stream-pty:{$sessionId}", [
        'type' => 'ssh',
        'host' => $ssh['host'],
        'username' => $ssh['username'],
        'password' => $ssh['password'],
        'port' => $ssh['port'],
        'timeout' => 10,
    ], $ttl);

    $payload = json_encode([
        'userId' => 1,
        'sessionId' => $sessionId,
        'exp' => time() + $ttl,
    ]);

    return [app('encrypter')->encrypt($payload), $sessionId];
}

function wsUrlWithToken(string $token): string
{
    return 'ws://'.WTS_FULLSTACK_HOST.':'.WTS_FULLSTACK_PORT.'/?token='.urlencode($token);
}

beforeEach(function () {
    requireSshTarget();
    $this->server = bootStreamServer();
});

afterEach(function () {
    if (isset($this->server) && is_resource($this->server)) {
        $status = proc_get_status($this->server);
        if ($status['running'] && ($status['pid'] ?? 0) > 0) {
            posix_kill($status['pid'], SIGTERM);
        }
        proc_terminate($this->server, SIGTERM);
        proc_close($this->server);
    }
});

describe('full-stack WebSocket server', function () {
    it('round-trips an echo through token auth, cache pull, and the SSH PTY', function () {
        [$token] = mintWsToken();

        $client = new WsClient(wsUrlWithToken($token));
        $client->setTimeout(10);
        $client->connect();

        expect($client->isConnected())->toBeTrue();

        // $((...)) expands remotely: only executed output matches the marker.
        $client->text("echo wts-fullstack-$((5000+771))\n");

        $received = '';
        $deadline = microtime(true) + 15.0;
        while (microtime(true) < $deadline && ! str_contains($received, 'wts-fullstack-5771')) {
            try {
                $message = $client->receive();
            } catch (WsException) {
                break;
            }
            if ($message instanceof WsText) {
                $received .= $message->getContent();
            }
        }

        $client->close();

        expect($received)->toContain('wts-fullstack-5771');
    });

    it('rejects a handshake with a disallowed Origin without burning the token', function () {
        [$token, $sessionId] = mintWsToken();

        $client = new WsClient(wsUrlWithToken($token));
        $client->setTimeout(5);
        $client->addHeader('Origin', 'http://evil.example');

        expect(fn () => $client->connect())->toThrow(WsException::class);

        // Origin is checked BEFORE the token: the single-use cache entry
        // must survive a rejected cross-origin handshake.
        expect(Cache::has("terminal-stream-pty:{$sessionId}"))->toBeTrue();
    });

    it('closes the connection for a garbage token', function () {
        $client = new WsClient(wsUrlWithToken('garbage-token'));
        $client->setTimeout(5);

        expect(fn () => $client->connect())->toThrow(WsException::class);
    });

    it('closes the connection when a token is reused (single-use cache pull)', function () {
        [$token, $sessionId] = mintWsToken();

        $first = new WsClient(wsUrlWithToken($token));
        $first->setTimeout(10);
        $first->connect();
        expect($first->isConnected())->toBeTrue();
        $first->close();

        // The server consumed the session config on the first handshake.
        expect(Cache::has("terminal-stream-pty:{$sessionId}"))->toBeFalse();

        $second = new WsClient(wsUrlWithToken($token));
        $second->setTimeout(5);

        expect(fn () => $second->connect())->toThrow(WsException::class);
    });
});
