<?php

declare(strict_types=1);

namespace MWGuerra\WebTerminalStream\Security;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Server-side custody for resolved connection configs.
 *
 * A Livewire public property is serialized into the `wire:snapshot` attribute
 * and shipped to the browser. `#[Locked]` does NOT change that — it stops the
 * CLIENT from *modifying* the value, not the server from *publishing* it. So
 * holding a resolved SSH config in a public property put the host, the port,
 * the username and the **private key** into the page HTML of every terminal.
 *
 * The components keep an opaque handle instead. The config itself lives here:
 * encrypted, in the cache, under a key that is the SHA-256 of a 64-char random
 * handle, and bound to the identity that created it — so a handle scraped from
 * one user's page is inert in anyone else's session.
 *
 * The window slides on every read: a dashboard left open all afternoon must
 * still connect when someone finally clicks a pane.
 */
final class ConnectionVault
{
    private const PREFIX = 'web-terminal-stream:conn:';

    /**
     * Take custody of a resolved config and return the handle standing in for it.
     *
     * @param  array<string, mixed>  $config
     */
    public function put(array $config): string
    {
        if ($config === []) {
            return '';
        }

        $handle = Str::random(64);

        Cache::put($this->key($handle), encrypt([
            'owner' => $this->owner(),
            'config' => $config,
        ]), $this->ttl());

        return $handle;
    }

    /**
     * The config behind a handle — or an empty array when it expired, was
     * tampered with, or belongs to somebody else. Callers treat empty as
     * "cannot connect" rather than as "connect with defaults", because a
     * silently-defaulted connection is how a terminal ends up somewhere nobody
     * asked for.
     *
     * @return array<string, mixed>
     */
    public function get(string $handle): array
    {
        if ($handle === '') {
            return [];
        }

        $stored = Cache::get($this->key($handle));

        if ($stored === null) {
            return [];
        }

        try {
            $payload = decrypt($stored);
        } catch (Throwable) {
            return [];
        }

        if (! is_array($payload) || ! hash_equals($this->owner(), (string) ($payload['owner'] ?? ''))) {
            return [];
        }

        Cache::put($this->key($handle), $stored, $this->ttl());

        return is_array($payload['config'] ?? null) ? $payload['config'] : [];
    }

    public function forget(string $handle): void
    {
        if ($handle !== '') {
            Cache::forget($this->key($handle));
        }
    }

    /**
     * Who a handle belongs to. Authenticated id when there is one, else the
     * session — a terminal without either is already refused by the Gate, but
     * an empty owner must never match a real one, so the guest form is prefixed
     * and can't collide with a numeric user id.
     */
    private function owner(): string
    {
        $id = Auth::id();

        if ($id !== null) {
            return 'user:'.$id;
        }

        try {
            $session = session()->getId();
        } catch (Throwable) {
            $session = null;
        }

        return $session ? 'session:'.$session : 'anonymous';
    }

    private function ttl(): int
    {
        return (int) config('web-terminal-stream.stream.connection_ttl', 7200);
    }

    /**
     * Hashed so the handle itself is never what sits in the cache store: a
     * cache dump then yields no usable handles.
     */
    private function key(string $handle): string
    {
        return self::PREFIX.hash('sha256', $handle);
    }
}
