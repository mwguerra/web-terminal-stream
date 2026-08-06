<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use MWGuerra\WebTerminalStream\Models\TerminalLog;

beforeEach(function () {
    Schema::create('terminal_stream_logs', function ($table) {
        $table->id();
        $table->unsignedBigInteger('user_id')->nullable();
        $table->string('terminal_session_id', 36);
        $table->string('event_type', 20);
        $table->string('connection_type', 10);
        $table->timestamps();
    });
});

afterEach(function () {
    Schema::dropIfExists('terminal_stream_logs');
});

function seedLog(int $daysAgo): void
{
    $log = TerminalLog::create([
        'terminal_session_id' => 'sess-'.$daysAgo,
        'event_type' => TerminalLog::EVENT_CONNECTED,
        'connection_type' => 'local',
    ]);

    // created_at is not fillable; backdate it with a raw update so the model's
    // timestamp handling does not stamp it back to "now".
    TerminalLog::whereKey($log->getKey())->update([
        'created_at' => now()->subDays($daysAgo),
    ]);
}

describe('terminal-stream:logs:cleanup', function () {
    it('rejects a non-numeric --days value', function () {
        $this->artisan('terminal-stream:logs:cleanup', ['--days' => 'abc'])
            ->assertFailed()
            ->expectsOutputToContain('non-negative integer');
    });

    it('rejects a negative --days value (would otherwise delete everything)', function () {
        seedLog(1);

        $this->artisan('terminal-stream:logs:cleanup', ['--days' => '-5'])
            ->assertFailed();

        // The recent log must survive the rejected run.
        expect(TerminalLog::count())->toBe(1);
    });

    it('deletes only entries older than --days', function () {
        seedLog(100); // stale
        seedLog(1);   // fresh

        $this->artisan('terminal-stream:logs:cleanup', ['--days' => '30'])
            ->assertSuccessful();

        expect(TerminalLog::count())->toBe(1)
            ->and(TerminalLog::where('terminal_session_id', 'sess-1')->exists())->toBeTrue();
    });

    it('does not delete anything on a dry run', function () {
        seedLog(100);

        $this->artisan('terminal-stream:logs:cleanup', ['--days' => '30', '--dry-run' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('Would delete');

        expect(TerminalLog::count())->toBe(1);
    });
});
