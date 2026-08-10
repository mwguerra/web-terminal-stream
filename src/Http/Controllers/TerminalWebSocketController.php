<?php

declare(strict_types=1);

namespace MWGuerra\WebTerminalStream\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use MWGuerra\WebTerminalStream\Security\ConnectionPolicy;
use MWGuerra\WebTerminalStream\Security\ConnectionVault;

class TerminalWebSocketController extends Controller
{
    public function generateToken(Request $request): JsonResponse
    {
        // Same authorization boundary as the Livewire path: if a
        // useStreamTerminal Gate is defined it must allow the caller.
        if (Gate::has('useStreamTerminal') && ! Gate::allows('useStreamTerminal')) {
            abort(403, 'Unauthorized.');
        }

        // Preferred path: an opaque handle minted server-side, which the caller
        // cannot forge or point somewhere else. {@see ConnectionVault}
        $ref = $request->input('connectionRef');

        if (is_string($ref) && $ref !== '') {
            $config = app(ConnectionVault::class)->get($ref);

            if ($config === []) {
                abort(410, 'This terminal session is no longer valid. Reload the page to reconnect.');
            }
        } else {
            // Legacy path: the caller hands over a whole connection config. This
            // makes the app server dial wherever the CLIENT says, which with an
            // empty `ssh_allowed_hosts` is an SSH/SSRF pivot behind nothing but
            // the Gate. Kept for the documented standalone flow, but an app that
            // only uses the schema components should switch it off.
            if (! config('web-terminal-stream.security.allow_client_supplied_connections', true)) {
                abort(403, 'Client-supplied connection configs are disabled; use connectionRef.');
            }

            $config = $request->input('connectionConfig', []);

            if (! is_array($config)) {
                abort(422, 'connectionConfig must be an object.');
            }
        }

        // The connection target must be permitted by the server-side policy —
        // this is what stops a client-supplied config from reaching an
        // arbitrary local shell or an off-allow-list SSH host.
        $reason = (new ConnectionPolicy)->deniedReason($config);
        if ($reason !== null) {
            abort(403, $reason);
        }

        $sessionId = Str::uuid()->toString();
        $ttl = (int) config('web-terminal-stream.stream.signed_url_ttl', 300);

        // Store connection config in cache (one-time retrieval). Encrypted so
        // any SSH credentials inside it are not readable at rest in the store.
        Cache::put("terminal-stream-pty:{$sessionId}", encrypt($config), $ttl);

        $payload = json_encode([
            'userId' => $request->user()?->getAuthIdentifier(),
            'sessionId' => $sessionId,
            'exp' => time() + $ttl,
        ]);

        $token = app('encrypter')->encrypt($payload);
        $encodedToken = urlencode($token);

        $wsUrl = config('web-terminal-stream.stream.websocket_url');
        if ($wsUrl) {
            $separator = str_contains($wsUrl, '?') ? '&' : '?';
            $url = "{$wsUrl}{$separator}token={$encodedToken}";
        } else {
            $host = config('web-terminal-stream.stream.ratchet_host', '127.0.0.1');
            $port = config('web-terminal-stream.stream.ratchet_port', 8090);
            $protocol = $request->isSecure() ? 'wss' : 'ws';
            $url = "{$protocol}://{$host}:{$port}?token={$encodedToken}";
        }

        return response()->json([
            'token' => $token,
            'url' => $url,
            'sessionId' => $sessionId,
        ]);
    }
}
