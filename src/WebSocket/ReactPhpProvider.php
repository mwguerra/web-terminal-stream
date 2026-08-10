<?php

declare(strict_types=1);

namespace MWGuerra\WebTerminalStream\WebSocket;

use Illuminate\Contracts\Foundation\Application;
use MWGuerra\WebTerminalStream\Metrics\ServerMetrics;
use MWGuerra\WebTerminalStream\Services\TerminalLogger;
use React\EventLoop\Loop;
use React\Socket\ConnectionInterface;
use React\Socket\SocketServer;

class ReactPhpProvider implements WebSocketProviderInterface
{
    private Application $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    /**
     * @param  bool  $reusePort  Bind with SO_REUSEPORT so sibling worker
     *                           processes can share the port and the kernel
     *                           spreads accepts between them. Only meaningful
     *                           when the caller forked workers first.
     */
    public function start(string $host, int $port, bool $reusePort = false): void
    {
        $config = $this->app['config']->get('web-terminal-stream.stream', []);

        $registry = new PtySessionRegistry(
            $this->app->storagePath('web-terminal-stream')
        );

        $maxLifetime = $config['max_session_lifetime'] ?? 3600;

        // Reap orphaned PIDs from a previous crash. Only signal a PID whose
        // identity we can still vouch for — killing a recycled PID would take
        // down an unrelated process.
        $reapRegistry = function () use ($registry, $maxLifetime): void {
            $canSignal = function_exists('posix_kill');
            foreach ($registry->cleanupStale($maxLifetime) as $session) {
                if ($canSignal && PtySessionRegistry::pidIsReapable($session)) {
                    posix_kill((int) $session['pid'], 9);
                }
            }
        };

        $reapRegistry();

        $server = new ReactPhpWebSocketServer(
            $registry,
            $this->app['encrypter'],
            $config,
            $this->app->make(TerminalLogger::class),
        );

        $loop = Loop::get();

        $sslCert = $config['ssl_cert'] ?? null;
        $sslKey = $config['ssl_key'] ?? null;
        $context = [];

        if ($sslCert && $sslKey && file_exists($sslCert) && file_exists($sslKey)) {
            $uri = "tls://{$host}:{$port}";
            $context = [
                'tls' => [
                    'local_cert' => $sslCert,
                    'local_pk' => $sslKey,
                    'allow_self_signed' => true,
                    'verify_peer' => false,
                ],
            ];
        } else {
            $uri = "{$host}:{$port}";
        }

        if ($reusePort) {
            // Every worker binds the SAME port and the kernel spreads incoming
            // connections between them. This is what makes a worker's blocking
            // SSH handshake stall only ITS OWN sessions instead of everyone's:
            // with N workers the head-of-line blast radius is 1/N.
            // No merge needed: the branches above only ever set 'tls'.
            $context['tcp'] = ['so_reuseport' => true];
        }

        $socket = new SocketServer($uri, $context, $loop);

        $socket->on('connection', function (ConnectionInterface $conn) use ($server) {
            $server->handleConnection($conn);
        });

        // How PTY output reaches the client.
        //
        // 'event' (default): the loop watches each session's transport and
        // wakes us the instant bytes land, so an IDLE session costs nothing.
        // A slow sweep still runs as a backstop — phpseclib can hold decoded
        // bytes in its own buffer with the socket no longer readable, and a
        // missed wakeup must degrade to "slightly late", never to "silent".
        //
        // 'poll': the historical 10ms sweep of every session. Kept reachable
        // because reverting a config key beats downgrading a package.
        $ioMode = ($config['io_mode'] ?? 'event') === 'poll' ? 'poll' : 'event';
        $server->attachLoop($loop, $ioMode);

        $sweepInterval = $ioMode === 'event'
            ? (float) ($config['backstop_sweep_seconds'] ?? 0.25)
            : 0.01;

        $loop->addPeriodicTimer($sweepInterval, function () use ($server) {
            $server->tick();
        });

        // Metrics. Each worker publishes its own snapshot — workers share
        // nothing, so a fleet view has to be assembled from per-worker files
        // (see MetricsReader) rather than read from any single process.
        $metrics = new ServerMetrics($this->app->storagePath('web-terminal-stream'));
        $server->attachMetrics($metrics);
        $metrics->publish($server->liveSessions());

        $publishEvery = (float) ($config['metrics_interval_seconds'] ?? 5);

        $loop->addPeriodicTimer($publishEvery, function () use ($metrics, $server): void {
            $metrics->publish($server->liveSessions());
        });

        // Periodic cleanup (every 60s): reap orphaned OS processes AND close
        // the matching WebSockets for any session that outlived its lifetime.
        $loop->addPeriodicTimer(60, function () use ($reapRegistry, $server, $maxLifetime) {
            $reapRegistry();
            $server->reapExpired($maxLifetime);
        });

        // Graceful shutdown: tear down every PTY / SSH channel on Ctrl-C or a
        // supervisor's SIGTERM instead of orphaning them for the next reap.
        $shutdown = function () use ($server, $loop, $metrics): void {
            $server->shutdown();
            // Withdraw this worker's snapshot so a planned shutdown does not
            // look like a crashed worker to whoever is reading the fleet.
            $metrics->retire();
            $loop->stop();
        };

        // ReactPHP's addSignal needs ext-pcntl (or ev/event); guard so the
        // server still boots on a build without it — it just won't shut down
        // as gracefully.
        if (function_exists('pcntl_signal') && defined('SIGINT') && defined('SIGTERM')) {
            foreach ([SIGINT, SIGTERM] as $signal) {
                $loop->addSignal($signal, $shutdown);
            }
        }

        $loop->run();
    }

    public function stop(): void
    {
        Loop::get()->stop();
    }

    public function sendToConnection(string $sessionId, string $data): void
    {
        // Not used directly — the ReactPhpWebSocketServer handles output streaming via tick()
    }
}
