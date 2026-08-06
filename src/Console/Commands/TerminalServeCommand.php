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
                            {--port= : The port to listen on}';

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

        $this->info("Starting WebSocket server on {$host}:{$port}...");
        $this->info('Press Ctrl+C to stop.');

        $provider = new ReactPhpProvider($this->laravel);
        $provider->start($host, (int) $port);

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
