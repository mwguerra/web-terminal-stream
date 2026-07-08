<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use MWGuerra\WebTerminalStream\Filament\Resources\TerminalLogResource;
use MWGuerra\WebTerminalStream\Models\TerminalLog;
use MWGuerra\WebTerminalStream\Services\TerminalLogger;

// These tests need a real table; the base TestCase runs no migrations, so we
// build a minimal terminal_stream_logs on the in-memory testing connection.
beforeEach(function () {
    Schema::create('terminal_stream_logs', function ($table) {
        $table->id();
        $table->unsignedBigInteger('tenant_id')->nullable();
        $table->unsignedBigInteger('user_id')->nullable();
        $table->string('terminal_session_id', 36);
        $table->string('terminal_identifier')->nullable();
        $table->string('event_type', 20);
        $table->string('connection_type', 10);
        $table->string('host')->nullable();
        $table->integer('port')->nullable();
        $table->string('ssh_username')->nullable();
        $table->text('command')->nullable();
        $table->longText('output')->nullable();
        $table->integer('exit_code')->nullable();
        $table->unsignedInteger('execution_time_seconds')->nullable();
        $table->string('ip_address', 45)->nullable();
        $table->string('user_agent')->nullable();
        $table->json('metadata')->nullable();
        $table->timestamps();
    });
});

afterEach(function () {
    Schema::dropIfExists('terminal_stream_logs');
});

describe('TerminalLogger::logServerDisconnection', function () {
    it('records a disconnected row for a session that has none', function () {
        $logger = new TerminalLogger(['enabled' => true, 'log_disconnections' => true]);

        $log = $logger->logServerDisconnection('sess-1', 42, 'ssh');

        expect($log)->not->toBeNull()
            ->and($log->event_type)->toBe(TerminalLog::EVENT_DISCONNECTED)
            ->and($log->user_id)->toBe(42)
            ->and($log->connection_type)->toBe('ssh')
            ->and(TerminalLog::where('terminal_session_id', 'sess-1')->count())->toBe(1);
    });

    it('does not double-log when a disconnect already exists for the session', function () {
        $logger = new TerminalLogger(['enabled' => true, 'log_disconnections' => true]);

        // Simulate the browser having already logged a clean disconnect.
        TerminalLog::create([
            'terminal_session_id' => 'sess-2',
            'event_type' => TerminalLog::EVENT_DISCONNECTED,
            'connection_type' => 'local',
        ]);

        $log = $logger->logServerDisconnection('sess-2', 1, 'local');

        expect($log)->toBeNull()
            ->and(TerminalLog::where('terminal_session_id', 'sess-2')->count())->toBe(1);
    });

    it('returns null when disconnection logging is disabled', function () {
        $logger = new TerminalLogger(['enabled' => true, 'log_disconnections' => false]);

        expect($logger->logServerDisconnection('sess-3', 1, 'local'))->toBeNull()
            ->and(TerminalLog::where('terminal_session_id', 'sess-3')->count())->toBe(0);
    });

    it('defaults the connection type to local when omitted', function () {
        $logger = new TerminalLogger(['enabled' => true, 'log_disconnections' => true]);

        $log = $logger->logServerDisconnection('sess-4');

        expect($log->connection_type)->toBe(TerminalLog::CONNECTION_LOCAL);
    });
});

describe('TerminalLogResource tenant scoping', function () {
    it('does not scope when no tenant column is configured', function () {
        config()->set('web-terminal-stream.logging.tenant_column', null);

        $sql = TerminalLogResource::getEloquentQuery()->toSql();

        expect($sql)->not->toContain('tenant_id');
    });

    it('scopes to the resolved tenant when configured', function () {
        config()->set('web-terminal-stream.logging.tenant_column', 'tenant_id');
        config()->set('web-terminal-stream.logging.tenant_resolver', fn () => 99);

        $query = TerminalLogResource::getEloquentQuery();

        expect($query->toSql())->toContain('tenant_id')
            ->and($query->getBindings())->toContain(99);
    });

    it('does not scope when the resolver returns null', function () {
        config()->set('web-terminal-stream.logging.tenant_column', 'tenant_id');
        config()->set('web-terminal-stream.logging.tenant_resolver', fn () => null);

        expect(TerminalLogResource::getEloquentQuery()->toSql())->not->toContain('tenant_id');
    });
});
