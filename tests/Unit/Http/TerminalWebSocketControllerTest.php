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

    it('caches connection config for the session', function () {
        $user = new User;
        $user->id = 1;
        $this->actingAs($user);

        $response = $this->postJson(route('web-terminal-stream.ws-token'), [
            'connectionConfig' => ['type' => 'local', 'timeout' => 30],
        ]);

        $sessionId = $response->json('sessionId');
        $cached = Cache::get("terminal-stream-pty:{$sessionId}");
        expect($cached)->toBe(['type' => 'local', 'timeout' => 30]);
    });

    it('requires authentication', function () {
        $response = $this->postJson(route('web-terminal-stream.ws-token'));
        $response->assertUnauthorized();
    });
});
