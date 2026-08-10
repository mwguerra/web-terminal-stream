<?php

declare(strict_types=1);

namespace MWGuerra\WebTerminalStream\Console\Commands;

use Illuminate\Console\Command;
use MWGuerra\WebTerminalStream\WebSocket\ReactPhpProvider;
use React\Socket\SocketServer;

class TerminalServeCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'terminal-stream:serve
                            {--host= : The host to bind to}
                            {--port= : The port to listen on}
                            {--workers= : Worker processes sharing the port (default 1)}';

    /**
     * The console command description.
     */
    protected $description = 'Start the WebSocket server for Stream terminal mode';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (! class_exists(SocketServer::class)) {
            $this->error('ReactPHP is not installed. Run: composer require react/socket react/event-loop ratchet/rfc6455');

            return self::FAILURE;
        }

        // Use ?: not ?? so an empty --host= / --port= falls back to config
        // instead of binding to an empty host or port 0.
        $host = $this->option('host') ?: config('web-terminal-stream.stream.ratchet_host', '127.0.0.1');
        $port = $this->option('port') ?: config('web-terminal-stream.stream.ratchet_port', 8090);

        foreach (self::capabilityWarnings(
            hasPosix: function_exists('posix_kill'),
            hasPcntl: function_exists('pcntl_signal'),
            osFamily: PHP_OS_FAMILY,
        ) as $warning) {
            $this->warn('[preflight] '.$warning);
        }

        $workers = (int) ($this->option('workers') ?: config('web-terminal-stream.stream.workers', 1));

        if ($workers > 1) {
            return $this->serveWithWorkers($host, (int) $port, $workers);
        }

        $this->info("Starting WebSocket server on {$host}:{$port}...");
        $this->info('Press Ctrl+C to stop.');

        $provider = new ReactPhpProvider($this->laravel);
        $provider->start($host, (int) $port);

        return self::SUCCESS;
    }

    /**
     * Run N independent server processes sharing one port.
     *
     * WHY: a session's SSH connect + auth runs synchronously on its server's
     * event loop, so while one connects, every session on THAT loop stalls. The
     * fix that does not mean rewriting the SSH layer is to have more than one
     * loop: with N workers a connect storm freezes 1/N of the fleet instead of
     * all of it, and the phpseclib crypto spreads across N cores.
     *
     * Each worker binds the same port with SO_REUSEPORT rather than inheriting
     * one listening socket, which keeps the child startup identical to the
     * single-process path — no descriptor passing, no shared accept queue to
     * reason about.
     *
     * A session lives entirely inside the worker that accepted it, and the
     * token→config handoff goes through the shared cache, so no worker needs to
     * know anything about another.
     */
    private function serveWithWorkers(string $host, int $port, int $workers): int
    {
        if (! function_exists('pcntl_fork')) {
            $this->error('--workers needs ext-pcntl. Install it, or run a single process.');

            return self::FAILURE;
        }

        $this->info("Starting {$workers} WebSocket workers on {$host}:{$port}...");
        $this->info('Press Ctrl+C to stop.');

        $children = [];
        $shuttingDown = false;

        $spawn = function () use ($host, $port): int {
            $pid = pcntl_fork();

            if ($pid === 0) {
                // Child: its own loop, its own sessions, its own bind.
                (new ReactPhpProvider($this->laravel))->start($host, $port, reusePort: true);
                exit(0);
            }

            return $pid;
        };

        for ($i = 0; $i < $workers; $i++) {
            $pid = $spawn();

            if ($pid > 0) {
                $children[$pid] = true;

                continue;
            }

            // Only the parent reaches here — the child exits inside $spawn — so
            // anything not a child pid is a failed fork.
            $this->error('Failed to fork a worker.');

            return self::FAILURE;
        }

        pcntl_async_signals(true);

        $stop = function (int $signal) use (&$children, &$shuttingDown): void {
            if ($shuttingDown) {
                return;
            }

            $shuttingDown = true;
            $this->info('[supervisor] shutting down; signalling workers.');

            // Pass the signal on so each worker runs its own graceful shutdown
            // and tears down its PTYs, rather than being killed with sessions
            // still open.
            foreach (array_keys($children) as $pid) {
                posix_kill($pid, $signal);
            }
        };

        foreach ([SIGINT, SIGTERM] as $signal) {
            pcntl_signal($signal, $stop);
        }

        // Supervise: a worker that dies takes its sessions with it, but the
        // fleet must not shrink silently for the rest of the process's life.
        //
        // WNOHANG + a short sleep rather than a blocking pcntl_wait(): a
        // blocking wait never yielded to the signal handler here, so SIGTERM
        // was accepted and then ignored — the supervisor and every worker
        // stayed up (reproduced 3/3 before this changed). Polling for exits is
        // cheap at this cadence and leaves an obvious point where pending
        // signals get dispatched.
        while ($children !== []) {
            $pid = pcntl_wait($status, WNOHANG);

            if ($pid > 0) {
                unset($children[$pid]);

                if (! $shuttingDown) {
                    $this->warn("[supervisor] worker {$pid} exited; replacing it.");
                    $replacement = $spawn();

                    if ($replacement > 0) {
                        $children[$replacement] = true;
                    }
                }

                continue;
            }

            pcntl_signal_dispatch();
            usleep(200_000);
        }

        return self::SUCCESS;
    }

    /**
     * Runtime-capability warnings for the environment the server runs in.
     *
     * Local-shell PTYs depend on native extensions and Linux-only kernel
     * interfaces; SSH connections do not. The server still boots without them —
     * these warnings tell the operator exactly what will and won't work. Pure
     * (takes its inputs) so it is unit-testable without a real environment.
     *
     * @return array<int, string>
     */
    public static function capabilityWarnings(bool $hasPosix, bool $hasPcntl, string $osFamily): array
    {
        $warnings = [];

        if (! $hasPosix) {
            $warnings[] = 'ext-posix is not loaded: local-shell PTY resizing and orphaned-process cleanup are disabled. '
                .'Install ext-posix, or use SSH connections only.';
        }

        if (! $hasPcntl) {
            $warnings[] = 'ext-pcntl is not loaded: the server cannot trap SIGINT/SIGTERM, so it will not close live '
                .'PTYs gracefully on shutdown (they are reaped on the next start instead).';
        }

        if ($osFamily !== 'Linux') {
            $warnings[] = "Local-shell PTY resizing uses /proc and stty and only works on Linux (detected {$osFamily}). "
                .'SSH connections are unaffected.';
        }

        return $warnings;
    }
}
