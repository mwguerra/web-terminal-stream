<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Log;
use MWGuerra\WebTerminalStream\Services\TerminalLogger;

/*
|--------------------------------------------------------------------------
| The user id is whatever the application says it is
|--------------------------------------------------------------------------
|
| getUserId() was declared `?int` and returned auth()->id(). In an application
| that keys users by ULID (or UUID) that is a string, so under strict_types the
| return itself raised a TypeError — inside createLog(), whose catch swallowed
| every Throwable while claiming the table might not exist. The result: not one
| audit row was ever written, and nothing anywhere said why.
|
| Reproduced in production on a ULID-keyed panel: a real root shell was opened
| through the terminal and terminal_stream_logs stayed empty.
|
*/

function ulidKeyedUser(string $id): Authenticatable
{
    return new class($id) implements Authenticatable
    {
        public function __construct(private string $id) {}

        public function getAuthIdentifierName(): string
        {
            return 'id';
        }

        public function getAuthIdentifier(): string
        {
            return $this->id;
        }

        public function getAuthPasswordName(): string
        {
            return 'password';
        }

        public function getAuthPassword(): string
        {
            return '';
        }

        public function getRememberToken(): string
        {
            return '';
        }

        public function setRememberToken($value): void {}

        public function getRememberTokenName(): string
        {
            return '';
        }
    };
}

it('reads a string user id without throwing', function () {
    auth()->setUser(ulidKeyedUser('01kzqh8zkz9b8k09beag4ybqgv'));

    $logger = new TerminalLogger(['enabled' => true]);
    $method = new ReflectionMethod($logger, 'getUserId');

    expect($method->invoke($logger))->toBe('01kzqh8zkz9b8k09beag4ybqgv');
});

it('still reads an integer user id', function () {
    auth()->setUser(new class implements Authenticatable
    {
        public function getAuthIdentifierName(): string
        {
            return 'id';
        }

        public function getAuthIdentifier(): int
        {
            return 42;
        }

        public function getAuthPasswordName(): string
        {
            return 'password';
        }

        public function getAuthPassword(): string
        {
            return '';
        }

        public function getRememberToken(): string
        {
            return '';
        }

        public function setRememberToken($value): void {}

        public function getRememberTokenName(): string
        {
            return '';
        }
    });

    $logger = new TerminalLogger(['enabled' => true]);
    $method = new ReflectionMethod($logger, 'getUserId');

    expect($method->invoke($logger))->toBe(42);
});

it('says so when it cannot write the row instead of going quiet', function () {
    // No migrations are loaded in the unit suite, so the insert fails on a
    // missing table — the one failure this package is allowed to absorb. It
    // must still leave a trace: a logger that silently writes nothing is
    // indistinguishable from a logger with nothing to write, which is exactly
    // how the TypeError above went unnoticed.
    Log::spy();

    $logger = new TerminalLogger(['enabled' => true]);

    expect($logger->logConnection(['terminal_session_id' => 'sess-1']))->toBeNull();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message): bool => str_contains($message, 'terminal audit row'))
        ->once();
});
