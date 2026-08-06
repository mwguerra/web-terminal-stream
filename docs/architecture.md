# Architecture: The Stream Data Path

A contributor-focused walkthrough of how a keystroke travels from the browser to a shell process and back. For fluent-API and installation docs, see the README.

## Components at a glance

```
Browser                                    Laravel app (HTTP)             terminal-stream:serve (long-running CLI)
┌─────────────────────────────┐            ┌──────────────────────┐       ┌──────────────────────────────────┐
│ stream-terminal.blade.php   │  Livewire  │ StreamTerminal        │       │ ReactPhpProvider (event loop)    │
│  Alpine component           │───────────▶│  getWebSocketUrl()    │       │  └ ReactPhpWebSocketServer       │
│  └ ghostty-web Terminal     │            │  connect()/disconnect()│      │     └ TerminalPtyBridge (1/conn) │
│    (WASM, canvas rendering) │            └──────────────────────┘       │        └ PTY process or SSH shell│
│                             │   WebSocket (raw bytes + resize JSON)     │  PtySessionRegistry (JSON file)  │
│                             │◀═════════════════════════════════════════▶│                                  │
└─────────────────────────────┘            shared: cache + APP_KEY        └──────────────────────────────────┘
```

Two processes cooperate and never share memory — they communicate through two shared facilities only:

1. **The Laravel cache** — carries the connection config from the HTTP request to the WebSocket server.
2. **`APP_KEY`** — the WebSocket server decrypts the auth token with the same encrypter the HTTP side used to create it.

This is why `terminal-stream:serve` must run inside the same application (same `.env`) as the pages that embed the terminal.

## 1. Mount and boot

`Schemas\Components\WebTerminalStream` (or `Livewire\TerminalBuilder`) mounts the `Livewire\StreamTerminal` component with `#[Locked]` props: `connectionConfig`, appearance settings, `scripts`, and the logging overrides. The Blade view (`resources/views/stream-terminal.blade.php`) is one large Alpine component. For multi-pane layouts, `Schemas\Components\TerminalGrid` (a Filament Grid subclass) composes N `WebTerminalStream` panes into a flush CSS grid — it only configures the panes (frameless/square corners/behavior/height) and container CSS variables; each pane still follows the exact data path described here.

On `init()` the Alpine component:

- binds a single teardown handler to `beforeunload`, `pagehide`, and `livewire:navigating` (the last one is what makes SPA navigation clean up — see §6);
- registers an `IntersectionObserver`. For the auto behaviors, when the terminal first scrolls into view it calls `initStream()` (loads the ghostty-web WASM module, creates the `Terminal` + `FitAddon`, mounts the canvas into the `wire:ignore` container) and then `connect()`.

Connection timing is governed by the `connectionBehavior` prop (`Enums\ConnectionBehavior`, default `Always`) and a client-side state machine (`idle → connecting → connected → disconnected`):

- `Always` — auto-connect on visibility, no connection UI (the original behavior).
- `Auto` — auto-connect on visibility, plus a connect/disconnect toggle (header action, or floating overlay control when frameless) and a centered Reconnect affordance after a disconnect.
- `Manual` — the IntersectionObserver deliberately does **not** boot anything; a centered Connect affordance triggers `initStream()` + `connect()` on click. A never-opened Manual pane costs no canvas, no WebSocket, no PTY, and writes no log rows (server-side `connect()` only fires from `ws.onopen`).

A user-initiated disconnect closes the WebSocket with a clean client close (code 1000); the server-side close event terminates the PTY as usual (§6).

## 2. Token auth flow

`connect()` (Alpine) calls `$wire.getWebSocketUrl()` — a Livewire round trip into `StreamTerminal::getWebSocketUrl()`, which:

1. Denies if a `useStreamTerminal` Gate is defined and fails for the current user.
2. Generates a UUID `sessionId` and writes the terminal's `connectionConfig` to the cache under `terminal-stream-pty:{sessionId}` with a TTL of `stream.signed_url_ttl` (default 300s). This keeps SSH credentials out of the URL and out of the browser entirely.
3. Encrypts `{userId, sessionId, exp}` with the app encrypter — that string is the one-time token.
4. Returns `ws(s)://host:port?token=...` built from `stream.websocket_url` (explicit override, e.g. behind a proxy) or `stream.ratchet_host`/`ratchet_port`.

`Http\Controllers\TerminalWebSocketController` (`POST terminal-stream/ws-token`, `web` + `auth` middleware, route name `web-terminal-stream.ws-token`) exposes the same token issuance over HTTP for custom (non-Livewire) frontends.

On the server side, `ReactPhpWebSocketServer::handleHandshake()`:

1. Buffers raw socket data until a full HTTP request arrives, then negotiates the RFC6455 upgrade (`ratchet/rfc6455` `ServerNegotiator`).
2. Validates the `Origin` header against `stream.allowed_origins` (`WebSocket\OriginValidator`: normalized scheme + case-insensitive host + port, exact match). No match → HTTP 403 + warning log, **before** the token is consumed, so a rejected page cannot burn a stolen token. A missing `Origin` (non-browser client) passes through — browsers always send it on WebSocket upgrades. A literal `'*'` entry, or an empty list, disables the check.
3. Decrypts the `token` query param; rejects on decryption failure or expired `exp`.
4. `Cache::pull`s the connection config — **pull, not get**: the token is single-use; replaying it finds an empty cache slot and the connection is closed.
5. Builds a `Data\ConnectionConfig` from the cached array and starts a `TerminalPtyBridge`.

## 3. TerminalPtyBridge — one bridge per connection

The bridge pairs one WebSocket client with one shell:

- **Local** (`ConnectionType::Local`): `proc_open("setsid {shell} -il", ...)` with `['pty']` descriptors, `TERM=xterm-256color`, cwd from the connection config's `working_directory` (falling back to `stream.working_directory`, then `getcwd()`), and the config's `environment` merged over the server env. Output pipes are set non-blocking.
- **SSH** (`ConnectionType::SSH`): phpseclib3 `SSH2` login (password or private key + optional passphrase), then an explicit `CHANNEL_SHELL` open. After the handshake the timeout is dropped to 0.01s so reads are effectively non-blocking inside the event loop (`setTimeout(0)` in phpseclib means "block forever", not "non-blocking").

`write()` sends browser bytes to the PTY/SSH channel. `read()` drains whatever is buffered. `resize(cols, rows)` uses `stty` on the child's PTY device plus `SIGWINCH` for local, `setWindowSize()` for SSH. `terminate()` SIGTERMs then SIGKILLs the local process group, or disconnects the SSH channel.

## 4. The event loop

`TerminalServeCommand` → `ReactPhpProvider::start()`:

- opens a `react/socket` `SocketServer` (plain TCP, or TLS when `stream.ssl_cert`/`ssl_key` point at readable files);
- every **10ms** calls `ReactPhpWebSocketServer::tick()`, which for each live bridge reads pending PTY output and writes it to the WebSocket as an unmasked text frame;
- every **60s** runs stale-session cleanup (§5).

Incoming WebSocket messages are parsed once: a JSON object with `type: "resize"` becomes a `resize()` call; anything else is forwarded verbatim as keystrokes. `tick()` and `handleMessage()` wrap each bridge in try/catch — one dying session is closed and unregistered without poisoning the loop or the other sessions.

## 5. PtySessionRegistry lifecycle

The registry is a JSON file at `storage/web-terminal-stream/pty-sessions.json` mapping `sessionId → {pid, userId, createdAt}`. SSH sessions register with the sentinel pid `-1` (no local process).

Its whole purpose is surviving crashes: if `terminal-stream:serve` dies, orphaned shell processes keep running with nobody holding their pipes. On the next server start (and every 60s while running), `cleanupStale(stream.max_session_lifetime)` (default 3600s) removes expired entries and SIGKILLs any recorded pid that is still alive. Normal disconnects unregister immediately via `TerminalPtyBridge::terminate()`.

Storage is deliberately namespaced (`web-terminal-stream`) so a side-by-side install of `mwguerra/web-terminal` can never kill this package's PTYs, and vice versa.

## 6. Teardown and wire:navigate survival

The stale-buffer class of bugs lives here, so the rules are strict:

- The canvas container is `wire:ignore` — Livewire morphs never touch it. On re-`initStream()` the container is explicitly `replaceChildren()`-ed so an orphaned canvas from a previous session can't linger.
- `destroy()` disposes the `onData`/`onResize` disposables, closes the WebSocket, disposes the ghostty Terminal, and removes its own listeners. It is bound **once** and stored (`_teardownHandler`) so `removeEventListener` actually matches.
- `livewire:navigating` is part of the teardown set because Filament navigation is SPA-style: without it, WebSocket + PTY + scrollback leaked across page visits.
- `connect()` defensively closes any surviving WebSocket/disposables before opening a new one, so two parallel streams can never write to the same Terminal.

Server-side, the WebSocket close event terminates the bridge (kills the PTY) and unregisters the session.

## 7. Connection lifecycle events and logging

`StreamTerminal::connect()`/`disconnect()` (invoked by the Alpine `ws.onopen`/`onclose` handlers via `$wire`) are idempotent state flips that also:

- dispatch `TerminalConnectedEvent` / `TerminalDisconnectedEvent` (host apps can listen; `Listeners\TerminalLogListener` is an opt-in subscriber);
- direct-log through `Services\TerminalLogger` into the `terminal_stream_logs` table, honoring the per-terminal `->log()` overrides (`enabled`, `connections`, `identifier`, `metadata`) over the `logging.*` config defaults.

There is deliberately **no command-level logging**: the stream is a raw byte pipe with no command boundaries to record.

## 8. The workspace: dynamic tiling on top of the same path

`Schemas\Components\TerminalWorkspace` mounts `Livewire\StreamWorkspace` — a tmux-style container of N `StreamTerminal` children. Nothing in §§1–7 changes for a pane inside a workspace; the workspace only decides **which panes exist and where they sit**.

### Ownership contract

| Concern | Owner | Mechanism |
|---|---|---|
| Pane roster (paneId → server-held terminal props incl. connection config) | Livewire, `#[Locked]` | Mutated only by `splitPane()`/`closePane()`/`spawnPane()` |
| Split-tree topology + persisted ratios | Livewire, `#[Locked]`, authoritative | Same methods + `updateRatios()` (validated, clamped) |
| Live geometry, focus, zoom, prefix state machine, drag | Alpine (`Alpine.data('wtsWorkspace')` in the bundle) | Pure style updates; zero Livewire on any keystroke |
| Token issuance, PTY session, keystrokes | Each pane's own `StreamTerminal` | Untouched — exactly §§2–7 |

### The split tree

`Data\Layout\LayoutTree` holds pure array operations over the binary split tree (`{type: 'pane'|'split', id, orientation, ratio, first, second}`; `ratio` is the first child's share). tmux semantics live entirely here: a split replaces a pane leaf with an even split (old pane first), a close collapses the parent split into the sibling subtree. Split ids derive from the new pane id (`s-<paneId>`), so operations are deterministic and exhaustively unit-tested. Persistence later is `json_encode($tree)` — nothing else.

### Morph safety (the load-bearing design decision)

Panes render as a **flat list of absolutely-positioned keyed siblings**, never nested DOM. Splits append one sibling; closes remove one. Livewire skips keyed matched children on parent re-renders, so an existing pane's canvas and WebSocket are never re-rendered when a sibling splits or closes — a streaming `top` must not blink. Pane rects (`left/top/width/height` %) are Alpine-bound (`x-bind:style`), never server-rendered; dividers live in a `wire:ignore` overlay that Alpine owns via `x-for`.

Corollary rule: **every `x-*` attribute string on morphed elements must be render-stable.** The Alpine component is registered as `Alpine.data('wtsWorkspace')` with a static `x-data` attribute and reads its initial state from `$wire` in `init()`. Inlining server state (`@js($tree)`) into `x-data` would make each morph rewrite the attribute, which Alpine treats as remove+add — destroying and re-initializing the component and orphaning bindings on a dead scope.

### Keyboard interception above ghostty-web

ghostty-web consumes keys through a hidden textarea, so the workspace registers **one document-level capture-phase keydown listener** (guarded by `$el.contains(e.target)`) — the only interception point guaranteed to run first. The tmux state machine: prefix key arms (visual ring + badge, configurable timeout), the next key is matched against the `Data\Keymap` bindings (`split_horizontal`, `close_pane`, `zoom_pane`, `focus_*`, `resize_*`), prefix-prefix sends the literal control byte to the focused pane through a `wts-pane-send` CustomEvent that `stream-terminal.blade.php` forwards to its WebSocket, and unbound armed keys are swallowed. Nothing else is ever intercepted — typing latency is untouched.

### Resize and zoom

Divider drags and `resize_*` keys change one split's ratio client-side (rAF-throttled), then a 400ms-debounced `updateRatios()` persists it (server validates ids against its own tree and clamps to `workspace.min_pane_ratio`). Container→PTY size propagation is the pre-existing pipeline: FitAddon's ResizeObserver → `onResize` → WS `{type:'resize',cols,rows}` → `SIGWINCH`. Zoom sets the focused pane to `inset:0` and hides siblings with `visibility: hidden` — never `display:none`, which would collapse their dimensions and refit their PTYs; hidden panes keep streaming into scrollback.

### Workspace security invariant

The client can only ever send a pane id, an orientation string, and ratio floats. A new pane's connection config is derived server-side — a clone of the split-source pane's `#[Locked]` roster entry, or the `defaultPane()` template evaluated once at schema build — never from client input. Every mutating method re-checks the `useStreamTerminal` Gate, and `maxPanes` is enforced server-side. Each child pane then goes through the normal single-use token flow of §2.

## 9. Security model in one paragraph

There is no command whitelist — a PTY cannot be meaningfully whitelisted, so the package does not pretend to. The boundaries are: (1) who can render a page containing the component (your authz), (2) the optional `useStreamTerminal` Gate checked at token issuance, (3) the encrypted, expiring, single-use token required to open a WebSocket, (4) the `stream.allowed_origins` Origin allow-list enforced on the handshake (rejects cross-origin browser pages before they can consume a token — CSRF-shaped defense in depth), and (5) network reachability of the WebSocket port (bind to `127.0.0.1` and proxy, or firewall it). Anyone past those boundaries has a real shell with the privileges of the PHP/SSH user.
