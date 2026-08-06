<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

function logMigrations(): array
{
    return glob(database_path('migrations/*_create_terminal_stream_logs_table.php')) ?: [];
}

afterEach(function () {
    foreach (logMigrations() as $file) {
        @unlink($file);
    }
});

describe('terminal-stream:install', function () {
    it('rejects --with-tenant together with --no-tenant', function () {
        $this->artisan('terminal-stream:install', [
            '--with-tenant' => true,
            '--no-tenant' => true,
            '--no-interaction' => true,
        ])->assertFailed();
    });

    it('overwrites an existing migration in place with --force instead of duplicating', function () {
        File::ensureDirectoryExists(database_path('migrations'));

        // A migration for the table already exists from a previous install.
        $existing = database_path('migrations/2020_01_01_000000_create_terminal_stream_logs_table.php');
        File::put($existing, "<?php // stale placeholder\n");

        $this->artisan('terminal-stream:install', [
            '--migration' => true,
            '--no-tenant' => true,
            '--force' => true,
            '--no-interaction' => true,
        ])->assertSuccessful();

        // Exactly one create-table migration must remain — a second timestamped
        // file would make `migrate` fail on a duplicate Schema::create().
        expect(logMigrations())->toHaveCount(1);

        // And it was refreshed in place (real stub content, not the placeholder).
        expect(File::get($existing))->toContain('terminal_stream_logs')
            ->and(File::get($existing))->not->toContain('stale placeholder');
    });
});
