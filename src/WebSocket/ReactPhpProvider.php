<?php

declare(strict_types=1);

namespace MWGuerra\WebTerminalStream\WebSocket;

use Illuminate\Contracts\Foundation\Application;
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

    public function start(string $host, int $port): void
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

        $socket = new SocketServer($uri, $context, $loop);

        $socket->on('connection', function (ConnectionInterface $conn) use ($server) {
            $server->handleConnection($conn);
        });

        // Periodic PTY output streaming (every 10ms)
        $loop->addPeriodicTimer(0.01, function () use ($server) {
            $server->tick();
        });

        // Periodic cleanup (every 60s): reap orphaned OS processes AND close
        // the matching WebSockets for any session that outlived its lifetime.
        $loop->addPeriodicTimer(60, function () use ($reapRegistry, $server, $maxLifetime) {
            $reapRegistry();
            $server->reapExpired($maxLifetime);
        });

        // Graceful shutdown: tear down every PTY / SSH channel on Ctrl-C or a
        // supervisor's SIGTERM instead of orphaning them for the next reap.
        $shutdown = function () use ($server, $loop): void {
            $server->shutdown();
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
