# Migrating from `mwguerra/web-terminal`

This guide is for host applications that used **Stream mode** in `mwguerra/web-terminal` and want to move to this standalone, Stream-only package. The two packages are fully namespaced and can be installed side-by-side during the transition.

If you relied on **Classic mode** (command-by-command execution, command whitelisting, interactive tmux sessions), stay on `mwguerra/web-terminal` — those features intentionally do not exist here.

## 1. Composer

```bash
composer require mwguerra/web-terminal-stream
# once fully migrated:
composer remove mwguerra/web-terminal
```

(While the repo is private, add it as a VCS repository first — see the README's Installation section.)

## 2. Plugin registration

```php
// Before
use MWGuerra\WebTerminal\WebTerminalPlugin;
$panel->plugins([WebTerminalPlugin::make()]);

// After
use MWGuerra\WebTerminalStream\WebTerminalStreamPlugin;
$panel->plugins([WebTerminalStreamPlugin::make()]);
```

Plugin id changes from `web-terminal` to `web-terminal-stream`. Most fluent toggles are unchanged: `terminalNavigation()`, `terminalLogsNavigation()`, `withoutTerminalPage()`, `withoutTerminalLogs()`, `WebTerminalStreamPlugin::current()`. The `only()` alias was removed — use `components()` (same signature; `without*()` now subtracts from that whitelist instead of being silently ignored).

## 3. The schema component

```php
// Before (stream mode in the old package)
use MWGuerra\WebTerminal\Schemas\Components\WebTerminal;
use MWGuerra\WebTerminal\Enums\TerminalMode;

WebTerminal::make()
    ->local()
    ->mode(TerminalMode::Stream)        // or ->streamTerminal()->classicTerminal(false)
    ->streamTheme([...])

// After — stream is implicit
use MWGuerra\WebTerminalStream\Schemas\Components\WebTerminalStream;

WebTerminalStream::make()
    ->local()
    ->theme([...])
```

Custom pages extending `MWGuerra\WebTerminal\Filament\Pages\Terminal` should extend `MWGuerra\WebTerminalStream\Filament\Pages\Terminal` instead (or be regenerated with `php artisan terminal-stream:make-page`).

## 4. Removed fluent methods

Everything that only configured Classic mode is gone. What to do instead:

| Removed method | What to do instead |
|---|---|
| `mode()`, `dual()`, `defaultMode()`, `streamTerminal()`, `classicTerminal()` | Nothing — stream is the only mode |
| `allowedCommands()`, `addAllowedCommands()`, `allowAllCommands()` | Nothing — a raw PTY has no whitelist; control access instead (Gate `useStreamTerminal`, page authz) |
| `allow()`, `deny()`, `allowPipes()`, `allowRedirection()`, `allowChaining()`, `allowExpansion()`, `allowAllShellOperators()`, `allowInteractiveMode()` (and the `TerminalPermission` enum) | Nothing — shell operators and interactivity are inherent to a PTY |
| Presets: `readOnly()`, `fileBrowser()`, `gitTerminal()`, `dockerTerminal()`, `nodeTerminal()`, `artisanTerminal()` | Nothing — they were whitelist shortcuts |
| `timeout()`, `prompt()`, `historyLimit()`, `maxOutputLines()` | The real shell owns prompt/history; SSH timeout can be passed in the connection array (`'timeout' => 10`) |
| `environment()`, `shell()`, `loginShell()`, `path()`, `inheritPath()` | Pass `'environment' => [...]` inside the connection config array; set the server-wide shell via `WEB_TERMINAL_STREAM_SHELL` |
| `disconnectOnNavigate()`, `keepConnectedOnNavigate()`, `inactivityTimeout()`, `noInactivityTimeout()` | Stream always tears down on navigation; stale server-side sessions are reaped via `stream.max_session_lifetime` |
| `log(commands: ..., output: ...)` parameters | Removed — only connection lifecycle is logged; `log(enabled:, connections:, identifier:, metadata:)` remains |
| `TerminalBuilder::toHtml()` / `__toString()` | Use `TerminalBuilder::render()` |
| `WebTerminalEmbed` class alias | Use `WebTerminalStream` |

Still available (unchanged semantics): `local()`, `ssh()`, `connection()`, `workingDirectory()`, `height()`, `title()`, `chrome()`/`frameless()`, `squareCorners()`, `scripts()`, `log()`, `logMetadata()`, `key()`.

`connectionBehavior()` is not just accepted anymore — it is now fully implemented (`Manual` connect affordance, `Auto` disconnect/reconnect toggle, `Always` chromeless auto-connect). The default stays `Always`, which matches the old package's always-auto-connect Stream behavior, so nothing changes unless you opt in.

## 4b. Fluent API changes in this package (pre-1.0 clean break)

This package unified its API before its first tag. If you are coming from the
old package's Stream mode (or from an early checkout of this one):

| Old | New |
|---|---|
| `->windowControls(true)` | `->chrome(TerminalChrome::Full)` |
| `->windowControls(false)` | `->chrome(TerminalChrome::Minimal)` |
| `->startConnected()` | `->connectionBehavior(ConnectionBehavior::Auto)` |
| `->autoConnect()` | `->connectionBehavior(ConnectionBehavior::Always)` (the default — usually just delete the call) |
| `->streamTheme([...])` | `->theme([...])` |
| `TerminalBuilder::sshWithPassword($host, $user, $pass, $port)` | `->ssh(host: $host, username: $user, password: $pass, port: $port)` |
| `TerminalBuilder::sshWithKey($host, $user, $key, $pp, $port)` | `->ssh(host: $host, username: $user, privateKey: $key, passphrase: $pp, port: $port)` |
| `TerminalBuilder::withConfig($connectionConfig)` | `->connection($connectionConfig)` — SSH credentials now actually reach the terminal (bug fix) |
| `TerminalBuilder::connection(ConnectionType::SSH, [...])` | `->ssh([...])` or `->connection([...])` |
| `->ssh(key: $pem)` | `->ssh(privateKey: $pem)` |
| `TerminalGrid::terminals([...])` | `TerminalGrid::panes([...])` |
| `TerminalGrid::gap(8)` | `TerminalGrid::paneGap(8)` (boolean `gap()` is Filament's own again) |
| `ConnectionBehavior::AutoWithButton` / `'auto_with_button'` | `ConnectionBehavior::Auto` / `'auto'` |
| `ConnectionBehavior::AutoHidden` / `'auto_hidden'` | `ConnectionBehavior::Always` / `'always'` |
| `getEffectiveConnectionBehavior()` | `getConnectionBehavior()` (now never null) |
| `->log(['enabled' => true, 'identifier' => 'x'])` | `->log(enabled: true, identifier: 'x')` (named args only) |
| Livewire prop `streamTheme` | `theme` |
| Livewire props `showWindowControls`, `autoConnect` | Removed — derived from `chrome` / `connectionBehavior` |
| Plugin `->only([...])` | `->components([...])` |
| config `deprecations.emit_notices` / `WEB_TERMINAL_STREAM_DEPRECATIONS_EMIT_NOTICES` | Removed — nothing is deprecated anymore |
| Default height `350px` (fluent builders) | `400px` everywhere |
| `workingDirectory()` (schema component only) | Available on `TerminalBuilder` too |

## 5. Artisan commands

| Old | New |
|---|---|
| `terminal:install` | `terminal-stream:install` (the `--allow-*-commands` flags are gone) |
| `terminal:serve` | `terminal-stream:serve` |
| `terminal:make-page` | `terminal-stream:make-page` (no `--allow-*` flags) |
| `terminal:cleanup` | `terminal-stream:logs:cleanup` |

Update supervisor/systemd units and scheduled tasks accordingly. If both packages run WebSocket servers on one host, give this one its own port (`--port=8091` + `WEB_TERMINAL_STREAM_RATCHET_PORT=8091`).

## 6. Config file and env vars

- Config file: `config/web-terminal.php` → `config/web-terminal-stream.php` (publish tag `web-terminal-stream-config`). Only `logging`, `stream`, and `workspace` sections exist — all Classic keys (`allowed_commands`, `blocked_characters`, `rate_limit`, `session`, `ssh`, `ui`, `auditing`, `timeout`, `default_connection`) are gone, as are `stream.enabled` (always on) and the unconsumed `stream.websocket_provider`/`pty_grace_period`/`theme` keys.
- `stream.allowed_origins` exists again — and unlike in the old package it is now actually **enforced** on the WebSocket handshake (default `[env('APP_URL', 'http://localhost')]`; a literal `'*'` or an empty list disables the check). If your app serves terminals from more than one origin, list them all; see the README's "Origin allow-list" section.
- Env prefix: `WEB_TERMINAL_*` → `WEB_TERMINAL_STREAM_*`. Full mapping of the survivors:

| Old | New |
|---|---|
| `WEB_TERMINAL_STREAM_ENABLED` | (removed — always enabled) |
| `WEB_TERMINAL_RATCHET_HOST` / `_RATCHET_PORT` | `WEB_TERMINAL_STREAM_RATCHET_HOST` / `_RATCHET_PORT` |
| `WEB_TERMINAL_WEBSOCKET_URL` | `WEB_TERMINAL_STREAM_WEBSOCKET_URL` |
| `WEB_TERMINAL_SSL_CERT` / `_SSL_KEY` | `WEB_TERMINAL_STREAM_SSL_CERT` / `_SSL_KEY` |
| `WEB_TERMINAL_STREAM_CWD` | unchanged |
| `WEB_TERMINAL_LOGGING`, `_LOG_CONNECTIONS`, `_LOG_DISCONNECTIONS`, `_LOG_ERRORS`, `_MAX_OUTPUT_LOG`, `_LOG_RETENTION` | same names with `WEB_TERMINAL_STREAM_` prefix |

## 7. Database

Logs live in a new table, `terminal_stream_logs` (old: `terminal_logs`). Run the new migration:

```bash
php artisan terminal-stream:install --migration --migrate --no-interaction
```

The model class name is still `TerminalLog` (namespace `MWGuerra\WebTerminalStream\Models`) — update imports in any custom resources/queries. Old `terminal_logs` data stays with the old package; migrate rows manually if you need continuity (columns are identical).

Note: only connection/disconnection events are written by this package. Command/output/error rows exist in the schema for compatibility and host-app use, but nothing in the package produces them.

## 8. Routes, tokens, and other host-visible identifiers

| Surface | Old | New |
|---|---|---|
| ws-token endpoint | `POST terminal/ws-token` | `POST terminal-stream/ws-token` |
| Route name | `terminal.ws-token` | `web-terminal-stream.ws-token` |
| Livewire component alias | `stream-terminal` | `web-terminal-stream` |
| View/translation namespace | `web-terminal::` | `web-terminal-stream::` |
| Published views dir | `resources/views/vendor/web-terminal` | `resources/views/vendor/web-terminal-stream` |
| Cache key prefix (internal) | `terminal-pty:` | `terminal-stream-pty:` |
| PTY registry storage | `storage/web-terminal` | `storage/web-terminal-stream` |
| Dist stylesheet | `resources/dist/web-terminal.css` | `resources/dist/web-terminal-stream.css` |

If you published Blade views from the old package, re-publish from this one — the old overrides will not be picked up.

## 9. Events and logging code

- `CommandExecutedEvent` no longer exists. `TerminalConnectedEvent` / `TerminalDisconnectedEvent` keep their shape (namespace changed) and are now actually dispatched by the component on connect/disconnect.
- `TerminalLogListener` no longer has a `handleCommand` method.
- If you type-hinted `MWGuerra\WebTerminal\...` anywhere (listeners, policies, custom pages), switch the imports to `MWGuerra\WebTerminalStream\...`.
