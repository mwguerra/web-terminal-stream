# 01 — Security model soundness

**Area key:** `security`

## Summary

The Livewire path of the security model is genuinely sound: connectionConfig, panes, sources, and the tree are all #[Locked] and derived server-side, new-pane/new-source configs are clones of Locked roster entries (never client input), the useStreamTerminal Gate is re-checked at token issuance (StreamTerminal::getWebSocketUrl:100) and on every workspace/dashboard mutation, tokens are AES-encrypted with the app key (unforgeable), single-use is enforced via Cache::pull, expiry is checked (exp < time), and the Origin allow-list is correctly checked BEFORE the token is consumed so a rejected page can't burn a stolen token. The WS server binds 127.0.0.1 by default and TLS is wired when ssl_cert/ssl_key are set. HOWEVER, the REST token endpoint (TerminalWebSocketController::generateToken) completely breaks the model: it is always registered with only web+auth, does NOT check the useStreamTerminal Gate, and trusts a fully client-supplied connectionConfig — so any authenticated (not necessarily privileged) user can mint a valid token for an arbitrary local shell on the app host or an SSH pivot to any host/credentials, defeating every invariant the Livewire side enforces. Secondary issues: SSH credentials (password/private_key/passphrase) sit in the cache store in plaintext for the token TTL, the primary getWebSocketUrl fallback hardcodes plaintext ws:// even on https pages (no auto-wss), there is no rate-limit/per-user session cap on token issuance (shell fork DoS), and the token's userId is decorative (never enforced server-side).

## What exists and works

- Encrypted single-use token: AES-encrypted payload {userId,sessionId,exp} via app encrypter, unforgeable without APP_KEY (StreamTerminal.php:110-116)
- Single-use enforcement: config retrieved via Cache::pull keyed by random uuid sessionId; a second presentation of the same token gets null and is closed (ReactPhpWebSocketServer.php:155-160)
- Expiry check: (payload['exp'] ?? 0) < time() closes the connection; missing exp defaults to 0 and is rejected (ReactPhpWebSocketServer.php:145-149)
- useStreamTerminal Gate re-checked at Livewire token issuance and on splitPane/closePane/spawnPane/updateRatios and dashboard toggle (StreamTerminal.php:100, StreamWorkspace.php:117/151/175/201, StreamDashboard.php:119)
- Server-side config derivation: connectionConfig/panes/sources/tree are all #[Locked]; new panes clone a Locked roster entry or the Locked template, client can only send a paneId/sourceId/orientation/ratio (StreamWorkspace.php:36-49/127/138, StreamDashboard.php:105/146)
- Origin allow-list checked before token decrypt/pull so a rejected origin never consumes the token (ReactPhpWebSocketServer.php:109-124)
- Origin normalization: scheme+lowercased host+default-port fill-in, exact match, malformed origins rejected, '*' wildcard escape hatch (OriginValidator.php:43-95)
- WS server binds 127.0.0.1 by default (config ratchet_host) and TLS listener is wired when ssl_cert/ssl_key exist (ReactPhpProvider.php:45-61)
- Server-to-client frames unmasked and client frames expect-masked per RFC6455; per-session failures are isolated so one bad bridge can't crash the shared loop (ReactPhpWebSocketServer.php:177-189/250-256)
- ws-token route is CSRF-protected (web middleware group) and auth-gated

## Gaps (missing functionality)

| Gap | Severity | Detail |
|---|:--:|---|
| No rate limit or per-user session cap on token issuance (shell fork DoS) | medium | Neither StreamTerminal::getWebSocketUrl nor TerminalWebSocketController::generateToken applies any throttle or per-user ceiling on how many tokens (hence PTY sessions) a user can create. maxPanes/maxOpen only bound the Livewire UI, not the number of independent connections. An authenticated user can script token minting + WS connects to spawn many local shells, each proc_open'ing a real process, only reaped after stream.max_session_lifetime (3600s default). This is a resource-exhaustion / fork-bomb vector on the app host. |
| No auto-TLS: default WS URL is plaintext ws:// with no wss fallback on the primary path | medium | getWebSocketUrl's fallback (StreamTerminal.php:126) always builds ws://{host}:{port} regardless of whether the page is served over https, so unless the operator explicitly sets stream.websocket_url=wss://... or ssl_cert/ssl_key, all terminal traffic (keystrokes, full interactive shell output, live SSH session data) travels unencrypted. On an https page the browser will also block the ws:// connection as mixed content, so the primary path is simultaneously insecure and broken over TLS. The REST controller does honor request isSecure() (TerminalWebSocketController.php:34) but the main Livewire path does not, an inconsistency. |
| No SSH host allow-list — server is a usable SSH/port-scan pivot | medium | There is no configurable allow-list of destination hosts/ports for SSH connections anywhere in the code (ConnectionConfig only range-checks the port). Combined with the client-controlled connectionConfig in the ws-token controller, an authenticated user can point an SSH terminal at any internal or external host, turning the app server into an SSRF/port-scan/pivot node reaching hosts the user could not reach directly. |

## Flaws (implemented but wrong/risky)

Each critical/high flaw was handed to an independent skeptic instructed to refute it.

### REST token endpoint trusts client-supplied connectionConfig AND skips the useStreamTerminal Gate — any authenticated user gets an arbitrary shell / SSH pivot
- **Severity:** critical — **Verdict:** ✅ confirmed
- **Files:** src/Http/Controllers/TerminalWebSocketController.php, src/WebTerminalStreamServiceProvider.php, src/WebSocket/ReactPhpWebSocketServer.php
- **Detail:** TerminalWebSocketController::generateToken reads the entire connection config straight from client input ($request->input('connectionConfig', []), line 18) and performs NO useStreamTerminal Gate check, then encrypts a valid single-use token and returns the WS URL. The route is registered unconditionally in boot() with only ['web','auth'] middleware (WebTerminalStreamServiceProvider.php:46-49). This defeats the entire server-side-derivation invariant the Livewire components enforce with #[Locked] props and clone-only pane derivation. A low-privilege but authenticated user can POST {connectionConfig:{type:'local'}} to obtain a token for a raw shell on the application host (the configured stream.shell), or {type:'ssh',host,username,password} to open an SSH session to any host with any credentials. The WS server does not re-validate the config against anything server-held — it does ConnectionConfig::fromArray($configData) on whatever was cached (ReactPhpWebSocketServer.php:166). So the two boundaries the design relies on (the Gate and server-derived config) are both absent on this path. Exploitability depends only on reaching the WS port, which in dev is the same machine as the browser and in production is wherever the operator exposed ratchet_host/websocket_url.
- **Evidence:** src/Http/Controllers/TerminalWebSocketController.php:18 `$config = $request->input('connectionConfig', []);` then Cache::put(...$config...) with NO Gate::allows('useStreamTerminal') anywhere in the method; compare StreamTerminal.php:100 which does gate. Route always on: WebTerminalStreamServiceProvider.php:46-49 `->middleware(['web','auth'])`.
- **Verifier:** Claim confirmed by direct reading. TerminalWebSocketController::generateToken (src/Http/Controllers/TerminalWebSocketController.php:18,22) takes connectionConfig straight from client input and caches it verbatim (asserted by tests/Unit/Http/TerminalWebSocketControllerTest.php:40), with NO Gate::allo

### SSH credentials stored in the cache store in plaintext for the token TTL
- **Severity:** high — **Verdict:** ✅ confirmed
- **Files:** src/Livewire/StreamTerminal.php, src/Http/Controllers/TerminalWebSocketController.php, src/Data/ConnectionConfig.php
- **Detail:** Both issuance paths write the full connectionConfig — including SSH password, private_key, and passphrase — into the cache in cleartext (Cache::put("terminal-stream-pty:{sessionId}", $config, $ttl)). Only the token payload is encrypted; the cached credential blob is not. Whatever cache driver the host app uses (redis, database, memcached, file) therefore holds live SSH secrets in plaintext for up to signed_url_ttl (300s), or the full TTL if the token is never consumed. A shared or dumpable cache store, a Redis MONITOR/KEYS, or a cache backup leaks credentials. The token being single-use limits replay of the SESSION but does nothing to protect the at-rest plaintext credentials during the window.
- **Evidence:** src/Livewire/StreamTerminal.php:108 `Cache::put("terminal-stream-pty:{$sessionId}", $this->connectionConfig, $ttl);` and src/Http/Controllers/TerminalWebSocketController.php:22 store the raw config; ConnectionConfig::toTransportArray (Data/ConnectionConfig.php:187-201) confirms password/private_key/passphrase are part of the transported config.
- **Verifier:** Factually accurate. getConnectionConfig() serializes credentials via toTransportArray() (ConnectionConfig.php:187-201 includes password/private_key/passphrase), and both issuance paths store that array unencrypted in the cache: StreamTerminal.php:108 and TerminalWebSocketController.php:22 both call

### Token userId is decorative — never enforced server-side
- **Severity:** low — **Verdict:** ⚪ unverified
- **Files:** src/WebSocket/ReactPhpWebSocketServer.php, src/WebSocket/PtySessionRegistry.php
- **Detail:** The WS server decodes userId from the token (ReactPhpWebSocketServer.php:152) and passes it to the bridge/registry purely for bookkeeping; it is never compared to anything and no ownership binding exists between the userId and the pulled sessionId config. Because the sessionId is a random uuid and the token is encrypted + single-use, this does not by itself enable cross-user access, so the practical impact is low — but it means the security relies entirely on token secrecy and the (missing, on the REST path) gate, and audit/registry attribution can be trusted no more than the issuer that minted the token. Worth stating explicitly since the model's comments imply userId is meaningful.
- **Evidence:** src/WebSocket/ReactPhpWebSocketServer.php:151-169 — $userId is read then only forwarded to TerminalPtyBridge for registry logging; no authorization check uses it.

### Encrypted token is passed in the WebSocket URL query string
- **Severity:** low — **Verdict:** ⚪ unverified
- **Files:** src/Livewire/StreamTerminal.php, src/Http/Controllers/TerminalWebSocketController.php
- **Detail:** Both paths embed the token as ?token=... in the WS URL (StreamTerminal.php:122/126, TerminalWebSocketController.php:38). WebSocket upgrade request lines are commonly captured by reverse proxies and access logs, landing the (still single-use, still within-TTL) token in log files where it could be replayed before the legitimate client consumes it. Moving the token to a Sec-WebSocket-Protocol subprotocol header or a short-lived POST-then-cookie handshake would avoid URL logging. Low because the token is encrypted, single-use, and TTL-bounded.
- **Evidence:** src/Livewire/StreamTerminal.php:122 `$url = "{$wsUrl}{$separator}token={$encodedToken}";` and :126 `$url = "ws://{$host}:{$port}?token={$encodedToken}";`; src/Http/Controllers/TerminalWebSocketController.php:38 `"{$protocol}://{$host}:{$port}?token=".urlencode($token)`.

