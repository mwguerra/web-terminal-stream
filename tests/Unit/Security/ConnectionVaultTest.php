<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use MWGuerra\WebTerminalStream\Security\ConnectionVault;

describe('ConnectionVault', function () {
    it('round-trips a config through an opaque handle', function () {
        $vault = app(ConnectionVault::class);

        $handle = $vault->put(['type' => 'ssh', 'host' => 'db-01', 'private_key' => 'SECRET']);

        expect($handle)->not->toBe('')
            ->and($vault->get($handle))->toBe(['type' => 'ssh', 'host' => 'db-01', 'private_key' => 'SECRET']);
    });

    it('never stores the handle itself as the cache key', function () {
        $vault = app(ConnectionVault::class);
        $handle = $vault->put(['type' => 'ssh', 'host' => 'db-01']);

        // Someone who dumps the cache must not walk away with usable handles.
        expect(Cache::get("web-terminal-stream:conn:{$handle}"))->toBeNull()
            ->and(Cache::get('web-terminal-stream:conn:'.hash('sha256', $handle)))->not->toBeNull();
    });

    it('stores the config encrypted, not in the clear', function () {
        $vault = app(ConnectionVault::class);
        $handle = $vault->put(['type' => 'ssh', 'private_key' => 'SUPERSECRETKEY']);

        $raw = Cache::get('web-terminal-stream:conn:'.hash('sha256', $handle));

        expect($raw)->toBeString()
            ->and($raw)->not->toContain('SUPERSECRETKEY');
    });

    it('returns nothing for an unknown handle', function () {
        expect(app(ConnectionVault::class)->get('nope'))->toBe([])
            ->and(app(ConnectionVault::class)->get(''))->toBe([]);
    });

    it('returns nothing after the handle is forgotten', function () {
        $vault = app(ConnectionVault::class);
        $handle = $vault->put(['type' => 'ssh', 'host' => 'db-01']);

        $vault->forget($handle);

        expect($vault->get($handle))->toBe([]);
    });

    it('refuses a handle minted for a different identity', function () {
        // A handle scraped out of one user's page must be inert for anyone else,
        // otherwise the vault only moves the leak instead of closing it.
        $owner = new class extends User
        {
            public function getAuthIdentifier(): mixed
            {
                return 1;
            }
        };
        $other = new class extends User
        {
            public function getAuthIdentifier(): mixed
            {
                return 2;
            }
        };

        Auth::setUser($owner);
        $handle = app(ConnectionVault::class)->put(['type' => 'ssh', 'host' => 'db-01']);

        expect(app(ConnectionVault::class)->get($handle))->toBe(['type' => 'ssh', 'host' => 'db-01']);

        Auth::setUser($other);
        expect(app(ConnectionVault::class)->get($handle))->toBe([]);
    });

    it('treats an empty config as nothing to vault', function () {
        expect(app(ConnectionVault::class)->put([]))->toBe('');
    });

    it('survives a tampered payload instead of throwing', function () {
        $vault = app(ConnectionVault::class);
        $handle = $vault->put(['type' => 'ssh', 'host' => 'db-01']);

        Cache::put('web-terminal-stream:conn:'.hash('sha256', $handle), 'not-a-valid-ciphertext', 60);

        expect($vault->get($handle))->toBe([]);
    });
});
