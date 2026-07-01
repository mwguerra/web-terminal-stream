<?php

declare(strict_types=1);

namespace MWGuerra\WebTerminalStream\Console\Commands;

use Illuminate\Console\Command;
use MWGuerra\WebTerminalStream\Models\TerminalLog;
use MWGuerra\WebTerminalStream\Services\TerminalLogger;

class TerminalLogsCleanupCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'terminal-stream:logs:cleanup
                            {--days= : Number of days to retain logs (default from config)}
                            {--dry-run : Show how many records would be deleted without actually deleting}';

    /**
     * The console command description.
     */
    protected $description = 'Clean up old terminal log entries';

    /**
     * Execute the console command.
     */
    public function handle(TerminalLogger $logger): int
    {
        $days = $this->option('days')
            ? (int) $this->option('days')
            : (int) config('web-terminal-stream.logging.retention_days', 90);

        $dryRun = $this->option('dry-run');

        $this->info("Cleaning up terminal logs older than {$days} days...");

        if ($dryRun) {
            // Count records without deleting
            $count = TerminalLog::olderThan($days)->count();
            $this->info("Would delete {$count} log entries.");
            $this->comment('(Dry run - no records were actually deleted)');
        } else {
            $count = $logger->cleanup($days);
            $this->info("Deleted {$count} log entries.");
        }

        return self::SUCCESS;
    }
}
