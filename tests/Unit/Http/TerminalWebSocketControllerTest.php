<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Cache;

describe('TerminalWebSocketController', function () {
    it('generates an encrypted token with correct payload', function () {
        $user = new User;
        $user->id = 42;
        $this->actingAs($user);

        $response = $this->postJson(route('web-terminal-stream.ws-token'), [
            'connectionConfig' => ['type' => 'local'],
        ]);

        $response->assertOk();
        $data = $response->json();
        expect($data)->toHaveKeys(['token', 'url', 'sessionId']);

        // Verify token can be decrypted
        $payload = json_decode(app('encrypter')->decrypt($data['token']), true);
        expect($payload['userId'])->toBe(42);
        expect($payload['sessionId'])->toBe($data['sessionId']);
        expect($payload['exp'])->toBeGreaterThan(time());
    });

    it('caches the connection config encrypted at rest', function () {
        $user = new User;
        $user->id = 1;
        $this->actingAs($user);

        $response = $this->postJson(route('web-terminal-stream.ws-token'), [
            'connectionConfig' => ['type' => 'local', 'timeout' => 30],
        ]);

        $sessionId = $response->json('sessionId');
        $cached = Cache::get("terminal-stream-pty:{$sessionId}");

        // Stored encrypted — not the raw array — but decrypts to the config.
        expect($cached)->toBeString()
            ->and(decrypt($cached))->toBe(['type' => 'local', 'timeout' => 30]);
    });

    it('requires authentication', function () {
        $response = $this->postJson(route('web-terminal-stream.ws-token'));
        $response->assertUnauthorized();
    });

    it('denies issuance when the useStreamTerminal gate forbids', function () {
        Illuminate\Support\Facades\Gate::define('useStreamTerminal', fn ($user = null) => false);

        $user = new User;
        $user->id = 7;
        $this->actingAs($user);

        $this->postJson(route('web-terminal-stream.ws-token'), [
            'connectionConfig' => ['type' => 'local'],
        ])->assertForbidden();
    });

    it('refuses a local terminal when allow_local is disabled', function () {
        config()->set('web-terminal-stream.security.allow_local', false);

        $user = new User;
        $user->id = 8;
        $this->actingAs($user);

        $this->postJson(route('web-terminal-stream.ws-token'), [
            'connectionConfig' => ['type' => 'local'],
        ])->assertForbidden();
    });

    it('refuses an SSH host outside the allow-list', function () {
        config()->set('web-terminal-stream.security.ssh_allowed_hosts', ['allowed.example.com']);

        $user = new User;
        $user->id = 9;
        $this->actingAs($user);

        $this->postJson(route('web-terminal-stream.ws-token'), [
            'connectionConfig' => ['type' => 'ssh', 'host' => 'evil.example.com', 'username' => 'x', 'password' => 'y'],
        ])->assertForbidden();

        // ...but allows a host that IS on the list.
        $this->postJson(route('web-terminal-stream.ws-token'), [
            'connectionConfig' => ['type' => 'ssh', 'host' => 'allowed.example.com', 'username' => 'x', 'password' => 'y'],
        ])->assertOk();
    });
});
