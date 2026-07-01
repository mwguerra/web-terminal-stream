# Web Terminal Stream

A full interactive PTY terminal for Laravel + Filament, streamed over WebSocket. The browser side is a [ghostty-web](https://github.com/coder/ghostty-web) canvas — a real terminal emulator compiled to WASM — so full-screen TUI apps like `vim`, `htop`, `nano`, `tmux`, and `artisan tinker` just work. Connect to the local machine or to remote servers over SSH (password or key auth).

This package was extracted from [`mwguerra/web-terminal`](https://github.com/mwguerra/web-terminal). That package keeps its dual-mode terminal (Classic command-by-command execution + Stream); this one is **Stream-only**, fully renamed and namespaced so the two can be installed side-by-side in the same application.

## Version Compatibility

| Version | Filament | Laravel   | Livewire | PHP  |
|---------|----------|-----------|----------|------|
| 1.x     | 5.x      | 12.x–13.x | 4.x      | 8.3+ |

## Features

- **Real PTY over WebSocket** — a ReactPHP server bridges the browser to a shell process (local `proc_open` PTY or a phpseclib SSH shell); keystrokes and output stream as raw bytes, no Livewire round trips
- **ghostty-web canvas rendering** — proper terminal emulation in the browser: colors, cursor addressing, alternate screen, resize (`SIGWINCH`), full-screen TUI apps
- **Local and SSH connections** — SSH supports password and private-key auth (with optional passphrase), custom port, working directory, and environment variables
- **Frameless chrome and square corners** — `frameless()` drops the header (actions float over the canvas) and `squareCorners()` drops the outer radius, so terminals can tile edge-to-edge in grid layouts
- **Scripts** — reusable command sequences in a header dropdown, with optional `confirmBeforeRun` confirmation gates
- **Connection lifecycle auditing** — `TerminalConnectedEvent`/`TerminalDisconnectedEvent`, database logging, and a ready-made Filament Terminal Logs resource with stats widgets
- **Multi-tenant logging** — optional tenant column + resolver for SaaS applications
- **Theming** — pass any ghostty-web theme (background, foreground, fontSize, palette) per terminal
- **Filament integration** — plugin with a Terminal page and Logs resource, or embed the schema component in any Filament page/form
- **Localization** — English and Portuguese (BR) translations included
- **Dark mode** — full dark mode support via Filament

> **Warning — this is real shell access**
>
> This package hands the browser a live shell on a real server. Anything the PHP user (or the SSH user) can do, the terminal user can do: modify files, change configuration, stop services, delete data.
>
> Unlike `mwguerra/web-terminal`'s Classic mode, this package intentionally has **no command whitelisting** — a raw PTY cannot be meaningfully whitelisted (shells have aliases, expansions, editors that spawn shells, …), so pretending otherwise would be false security. **Access control is the boundary:**
>
> - Restrict the pages that embed a terminal to trusted technical personnel (Filament panel auth, policies).
> - Define a `useStreamTerminal` Gate — it is checked before every WebSocket token is issued.
> - Keep the WebSocket server bound to `127.0.0.1` behind a reverse proxy, or firewall its port.
> - Keep `stream.allowed_origins` accurate — browser WebSocket handshakes from other origins are rejected with 403 before the auth token is consumed.
> - Keep connection logging enabled for an audit trail.
>
> **Use at your own risk.** The authors are not responsible for any damage, data loss, or security incidents resulting from the use of this package.

## Installation

### Composer

The package is currently distributed from a private GitHub repository. Add the VCS repository and require it:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/mwguerra/web-terminal-stream"
        }
    ]
}
```

```bash
composer require mwguerra/web-terminal-stream
```

(For private repos, make sure your machine/CI can authenticate to GitHub — e.g. `composer config --global github-oauth.github.com <token>`.)

The WebSocket server's ReactPHP stack (`react/socket`, `react/event-loop`, `ratchet/rfc6455`) is a hard requirement and installs automatically.

### Interactive installer

```bash
php artisan terminal-stream:install
```

The installer publishes the config file and the `terminal_stream_logs` migration, and can scaffold a custom Terminal page and/or TerminalLogs resource into your app.

All flags:

```bash
# Choose components explicitly (non-interactive)
php artisan terminal-stream:install --config --migration --no-interaction

# Publish + run the migration
php artisan terminal-stream:install --config --migration --migrate --no-interaction

# Multi-tenant migration (adds tenant_id column) / explicitly without
php artisan terminal-stream:install --with-tenant
php artisan terminal-stream:install --no-tenant

# Publish Blade views for customization
php artisan terminal-stream:install --views

# Scaffold a custom Terminal page / TerminalLogs resource in your app
php artisan terminal-stream:install --page --resource --panel=admin

# Overwrite existing files
php artisan terminal-stream:install --force
```

### Manual setup

```bash
php artisan vendor:publish --tag=web-terminal-stream-config
php artisan vendor:publish --tag=web-terminal-stream-views   # optional
php artisan vendor:publish --tag=web-terminal-stream-lang    # optional
php artisan migrate
```

### The WebSocket server

The terminal cannot work without the PTY bridge. Start it from the same application (it shares the app's cache and `APP_KEY` with the HTTP side):

```bash
php artisan terminal-stream:serve                       # default 127.0.0.1:8090
php artisan terminal-stream:serve --host=0.0.0.0 --port=8090
```

For production, supervise it:

```ini
# Supervisor
[program:terminal-stream-serve]
command=php /path/to/artisan terminal-stream:serve
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/terminal-stream-serve.log
```

```ini
# systemd
[Unit]
Description=Web Terminal Stream WebSocket Server
After=network.target

[Service]
ExecStart=/usr/bin/php /path/to/artisan terminal-stream:serve
Restart=always
User=www-data

[Install]
WantedBy=multi-user.target
```

#### SSL / WSS

When your app is served over HTTPS, browsers require the terminal WebSocket to be WSS. Two options:

1. **Reverse proxy (recommended):** keep the server on `127.0.0.1:8090` and terminate TLS at nginx/Caddy, then point the package at the public URL:

   ```env
   WEB_TERMINAL_STREAM_WEBSOCKET_URL=wss://your-domain.test/terminal-ws
   ```

   ```nginx
   location /terminal-ws {
       proxy_pass http://127.0.0.1:8090;
       proxy_http_version 1.1;
       proxy_set_header Upgrade $http_upgrade;
       proxy_set_header Connection "upgrade";
       proxy_read_timeout 3600s;
   }
   ```

2. **Direct TLS:** give the server a certificate and it serves WSS itself:

   ```env
   WEB_TERMINAL_STREAM_SSL_CERT=/path/to/cert.crt
   WEB_TERMINAL_STREAM_SSL_KEY=/path/to/cert.key
   WEB_TERMINAL_STREAM_WEBSOCKET_URL=wss://your-domain.test:8090
   ```

#### Origin allow-list

The server validates the `Origin` header of every browser WebSocket handshake against `stream.allowed_origins` (default: `[env('APP_URL', 'http://localhost')]`). A handshake from an origin not on the list is rejected with HTTP 403 — and logged at warning level — *before* the single-use auth token is consumed, so a malicious page in a logged-in user's browser cannot open a socket even if it managed to obtain a token.

Matching is exact on the normalized origin (scheme + case-insensitive host + port, with default ports filled in per scheme — `https://app.test` and `https://app.test:443` are the same origin). Requests without an `Origin` header (non-browser clients, CLI tooling) are allowed through — browsers always send `Origin` on WebSocket upgrades, so this does not weaken browser-facing CSRF protection; the encrypted single-use token remains the auth gate.

```php
// config/web-terminal-stream.php
'stream' => [
    'allowed_origins' => [
        env('APP_URL', 'http://localhost'),
        'https://admin.example.com',
    ],
],
```

A literal `'*'` entry disables the check entirely — an escape hatch for reverse-proxy setups that strip or rewrite the `Origin` header. An empty (or missing) list also disables the check, so a published config predating this key keeps working; republish the config to get the secure default.

Because this is an array, it is **config-file-only** — there is no `WEB_TERMINAL_STREAM_*` env var for it (env vars carry scalars). Reference `env('APP_URL')` or your own env-driven values inside the published config file instead.

### Environment variables

| Variable | Config key | Default |
|---|---|---|
| `WEB_TERMINAL_STREAM_RATCHET_HOST` | `stream.ratchet_host` | `127.0.0.1` |
| `WEB_TERMINAL_STREAM_RATCHET_PORT` | `stream.ratchet_port` | `8090` |
| `WEB_TERMINAL_STREAM_WEBSOCKET_URL` | `stream.websocket_url` | `null` (built from host/port) |
| `WEB_TERMINAL_STREAM_SSL_CERT` | `stream.ssl_cert` | `null` |
| `WEB_TERMINAL_STREAM_SSL_KEY` | `stream.ssl_key` | `null` |
| `WEB_TERMINAL_STREAM_SHELL` | `stream.shell` | `/bin/bash` |
| `WEB_TERMINAL_STREAM_CWD` | `stream.working_directory` | `null` (falls back to `getcwd()`) |
| `WEB_TERMINAL_STREAM_LOGGING` | `logging.enabled` | `true` |
| `WEB_TERMINAL_STREAM_LOG_CONNECTIONS` | `logging.log_connections` | `true` |
| `WEB_TERMINAL_STREAM_LOG_DISCONNECTIONS` | `logging.log_disconnections` | `true` |
| `WEB_TERMINAL_STREAM_LOG_ERRORS` | `logging.log_errors` | `true` |
| `WEB_TERMINAL_STREAM_MAX_OUTPUT_LOG` | `logging.max_output_length` | `10000` |
| `WEB_TERMINAL_STREAM_LOG_RETENTION` | `logging.retention_days` | `90` |
| `WEB_TERMINAL_STREAM_DEPRECATIONS_EMIT_NOTICES` | `deprecations.emit_notices` | `false` |

Non-env config keys: `stream.max_session_lifetime` (default `3600` — stale PTYs older than this are killed by the server's cleanup pass), `stream.signed_url_ttl` (default `300` — lifetime of a WebSocket auth token), and `stream.allowed_origins` (default `[env('APP_URL', 'http://localhost')]` — see the Origin allow-list section; it's an array, so config-file-only).

## Usage

### Filament plugin

```php
use MWGuerra\WebTerminalStream\WebTerminalStreamPlugin;

public function panel(Panel $panel): Panel
{
    return $panel
        ->plugins([
            WebTerminalStreamPlugin::make(),
        ]);
}
```

The plugin registers a **Terminal** page and a **Terminal Logs** resource. Configure it fluently:

```php
WebTerminalStreamPlugin::make()
    // Navigation
    ->terminalNavigation(icon: 'heroicon-o-command-line', label: 'Console', sort: 10, group: 'Tools')
    ->terminalLogsNavigation(icon: 'heroicon-o-document-text', label: 'Console Logs', sort: 11, group: 'Tools')

    // Disable pieces
    ->withoutTerminalPage()
    ->withoutTerminalLogs()

    // Or register only specific components
    ->only([
        \MWGuerra\WebTerminalStream\Filament\Resources\TerminalLogResource::class,
    ]);
```

`WebTerminalStreamPlugin::current()` returns the registered instance from inside pages/components.

### The schema component

`WebTerminalStream::make()` is the public entry point. It works in any Filament schema (pages, forms):

```php
use MWGuerra\WebTerminalStream\Schemas\Components\WebTerminalStream;

public function schema(Schema $schema): Schema
{
    return $schema->components([
        WebTerminalStream::make()
            ->key('app-terminal')
            ->local()
            ->workingDirectory(base_path())
            ->height('500px')
            ->title('Application Console')
            ->log(enabled: true, connections: true, identifier: 'app-terminal'),
    ]);
}
```

For a custom page, extend the package page and override `schema()` (or run `php artisan terminal-stream:make-page`):

```bash
php artisan terminal-stream:make-page ServerConsole --panel=admin --key=server-console
```

### Standalone (without Filament schemas)

Mount the Livewire component directly:

```blade
<livewire:web-terminal-stream
    :connection-config="['type' => 'local']"
    height="400px"
    title="Terminal"
/>
```

Or use the builder in any Blade view:

```php
use MWGuerra\WebTerminalStream\Livewire\TerminalBuilder;

{!! (new TerminalBuilder)
    ->local()
    ->title('Console')
    ->height('400px')
    ->render() !!}
```

`TerminalBuilder` also offers `sshWithPassword(...)`, `sshWithKey(...)`, `connection(ConnectionType|string, array)`, `withConfig(ConnectionConfig)`, and `key(string)`.

### Connections

```php
// Local shell
WebTerminalStream::make()->local()

// SSH with password (named parameters)
WebTerminalStream::make()->ssh(
    host: '192.168.1.100',
    username: 'deploy',
    password: 'secret',
    port: 22,
)

// SSH with private key (+ optional passphrase)
WebTerminalStream::make()->ssh(
    host: 'prod.example.com',
    username: 'deploy',
    key: file_get_contents('/path/to/id_ed25519'),
    passphrase: 'optional-passphrase',
)

// SSH with an array (extra keys: timeout, working_directory, environment)
WebTerminalStream::make()->ssh([
    'host' => 'prod.example.com',
    'username' => 'deploy',
    'private_key' => Storage::get('keys/deploy'),
    'port' => 2222,
    'working_directory' => '/var/www',
    'environment' => ['APP_ENV' => 'production'],
])

// SSH with a Closure — resolved at render time, credentials never sit in the schema
WebTerminalStream::make()->ssh(fn () => [
    'host' => config('servers.prod.host'),
    'username' => config('servers.prod.user'),
    'private_key' => decrypt(config('servers.prod.key')),
])

// Value object
use MWGuerra\WebTerminalStream\Data\ConnectionConfig;

WebTerminalStream::make()->connection(
    ConnectionConfig::sshWithKey(host: 'example.com', username: 'deploy', privateKey: $key)
)
```

`ConnectionConfig` has static factories `local()`, `sshWithPassword()`, `sshWithKey()` and validates required fields.

### Fluent API reference

| Method | Description | Default |
|---|---|---|
| `key(string)` | Stable wire:key (auto-generated `web-terminal-stream-XXXXXXXX` otherwise) | random |
| `local()` | Local shell connection | this is the default |
| `ssh(...)` | SSH connection — named params, array, or Closure | — |
| `connection(array\|Closure\|ConnectionConfig)` | Set the raw connection config | `['type' => 'local']` |
| `workingDirectory(?string)` | Initial working directory for the shell | `null` |
| `height(string\|Closure)` | Terminal height (CSS value) | `'350px'` |
| `title(string\|Closure)` | Header title | `'Terminal'` |
| `chrome(TerminalChrome\|Closure)` | `Full`, `Minimal` (no window dots), or `None` (no header) | `Full` |
| `frameless()` | Shorthand for `chrome(TerminalChrome::None)` | — |
| `squareCorners(bool\|Closure)` | Drop outer border-radius for flush grid tiling | `false` |
| `streamTheme(array\|Closure)` | ghostty-web theme (`background`, `foreground`, `fontSize`, palette…) | `[]` |
| `scripts(array\|Closure)` | Script definitions for the header dropdown | `[]` |
| `log(...)` | Per-terminal logging overrides (see Logging) | config defaults |
| `logMetadata(array\|Closure)` | Metadata attached to every log entry | `[]` |
| `connectionBehavior(ConnectionBehavior)` | Declarative connect behavior — see note below | `null` |

Deprecated aliases kept for source compatibility with the parent package: `windowControls(bool)` (use `chrome()`), `startConnected()` and `autoConnect()` (use `connectionBehavior()`). Opt into runtime deprecation notices with `WEB_TERMINAL_STREAM_DEPRECATIONS_EMIT_NOTICES=true`.

> **Note on connection behavior:** the Stream terminal currently always auto-connects when it scrolls into view, and the header has no connect/disconnect button. `connectionBehavior()` (and the deprecated `startConnected()`/`autoConnect()`) are accepted and forwarded, but only `ConnectionBehavior::AutoHidden` matches what actually renders today; a manual-connect UI is a planned addition.

### Chrome levels and tiled layouts

`TerminalChrome` controls how much UI surrounds the canvas:

- `Full` — header with macOS-style window dots, title, and action buttons.
- `Minimal` — header without the dots.
- `None` — no header at all; the actions (scripts, copy, info) float over the canvas top-right.

Combined with `squareCorners()`, frameless terminals tile edge-to-edge — the building block for tmux-like multi-pane layouts:

```php
use Filament\Schemas\Components\Grid;
use MWGuerra\WebTerminalStream\Schemas\Components\WebTerminalStream;

Grid::make(2)->schema([
    WebTerminalStream::make()->key('pane-1')->local()->frameless()->squareCorners()->height('300px'),
    WebTerminalStream::make()->key('pane-2')->local()->frameless()->squareCorners()->height('300px'),
    WebTerminalStream::make()->key('pane-3')->ssh(host: 'staging', username: 'deploy', key: $key)
        ->frameless()->squareCorners()->height('300px'),
    WebTerminalStream::make()->key('pane-4')->local()->frameless()->squareCorners()->height('300px'),
])
```

Every `WebTerminalStream::make()` gets a unique wire:key automatically, so multiple terminals on one page stay isolated. Give each pane an explicit `->key()` when you need stable identities.

### Theming

The theme array is passed straight to the ghostty-web `Terminal` constructor:

```php
WebTerminalStream::make()
    ->local()
    ->streamTheme([
        'background' => '#1a1b26',
        'foreground' => '#a9b1d6',
        'fontSize' => 14,
    ])
```

The `background` value is also used for the component's own surface, so the canvas and its padding match.

### Scripts

Scripts are predefined command sequences exposed in a dropdown in the terminal header. Commands are typed into the PTY exactly as if the user had typed them, one per line:

```php
use MWGuerra\WebTerminalStream\Data\Script;

WebTerminalStream::make()
    ->local()
    ->scripts([
        Script::make('deploy')
            ->label('Deploy to Production')
            ->description('Pull, install, migrate, cache')
            ->commands([
                'git pull origin main',
                'composer install --no-dev',
                'php artisan migrate --force',
                'php artisan config:cache',
            ])
            ->confirmBeforeRun(),

        Script::make('logs')
            ->label('Tail Laravel Log')
            ->commands(['tail -f storage/logs/laravel.log']),
    ])
```

Plain arrays work too: `['key' => 'logs', 'label' => 'Tail Log', 'commands' => [...], 'confirmBeforeRun' => true]`.

Options the Stream terminal honors:

| Option | Description |
|---|---|
| `key` | Unique identifier (required) |
| `label` | Display name in the dropdown |
| `description` | Secondary line in the dropdown |
| `commands` | The command strings sent to the PTY |
| `confirmBeforeRun()` | First click arms an inline Confirm/Cancel prompt; only Confirm runs the script |

The `Script` DTO accepts additional options inherited from the parent package (`icon`, `stopOnError`, `elevated`, `requiredCommands`, `willDisconnect`, `beforeMessage`, `disconnectMessage`); they are carried through but **not currently rendered or enforced by the Stream UI** — since commands are raw PTY input, there is no per-command exit-code tracking. The confirmation gate is UX, not a security control: a connected user already has a full shell.

### Authorization

Define a `useStreamTerminal` Gate to control who may open a terminal session. It is checked every time a WebSocket token is requested:

```php
use Illuminate\Support\Facades\Gate;

Gate::define('useStreamTerminal', fn ($user) => $user->isAdmin());
```

No Gate defined = allowed (protect your pages regardless).

## Logging & Auditing

Connection lifecycle events (connect/disconnect) are recorded to the `terminal_stream_logs` table. There is no command-level logging — the stream is a raw byte pipe with no command boundaries.

### Per-terminal configuration

```php
WebTerminalStream::make()
    ->local()
    ->log(
        enabled: true,
        connections: true,
        identifier: 'admin-console',       // shows up in the Logs resource
        metadata: ['server' => 'web-01'],  // merged into every entry
    )

// Array or Closure forms also work
->log(['enabled' => true, 'identifier' => 'admin-console'])
->log(fn () => ['enabled' => auth()->user()->isAuditable()])

// Metadata alone
->logMetadata(['environment' => app()->environment()])
```

Per-terminal values override the `logging.*` config defaults. Set `logging.terminals` to a list of identifiers to log only specific terminals.

### Events

`StreamTerminal` dispatches standard Laravel events you can listen to:

```php
use MWGuerra\WebTerminalStream\Events\TerminalConnectedEvent;
use MWGuerra\WebTerminalStream\Events\TerminalDisconnectedEvent;

Event::listen(TerminalConnectedEvent::class, function (TerminalConnectedEvent $event) {
    // $event->sessionId, ->connectionType, ->host, ->port, ->sshUsername,
    // ->userId, ->terminalIdentifier, ->ipAddress, ->metadata
});
```

`MWGuerra\WebTerminalStream\Listeners\TerminalLogListener` is an opt-in event subscriber that writes the same log entries — don't register it if you keep the built-in direct logging enabled, or you'll get duplicates.

### The Logs resource

The Filament **Terminal Logs** resource lists entries with filters (event type, connection type, user, terminal, date range), a detail view, and stats widgets. Query the model directly with its scopes:

```php
use MWGuerra\WebTerminalStream\Models\TerminalLog;

TerminalLog::forSession($sessionId)->get();
TerminalLog::forTerminal('admin-console')->recent(48)->get();
TerminalLog::forUser($userId)->connections()->get();
```

### Retention & cleanup

```bash
php artisan terminal-stream:logs:cleanup             # uses logging.retention_days (default 90)
php artisan terminal-stream:logs:cleanup --days=30
php artisan terminal-stream:logs:cleanup --dry-run   # count only, delete nothing
```

Schedule it:

```php
Schedule::command('terminal-stream:logs:cleanup')->daily();
```

### Multi-tenant support

```php
// config/web-terminal-stream.php
'logging' => [
    'tenant_column' => 'tenant_id',
    'tenant_resolver' => fn () => auth()->user()?->tenant_id,   // or an invokable class name
],
```

Install the tenant-aware migration with `php artisan terminal-stream:install --with-tenant`, then filter with `TerminalLog::forTenant($tenantId)`.

## Troubleshooting

**The terminal shows but never connects**
- Is `php artisan terminal-stream:serve` running — in the *same app/env* as the web request? The server decrypts tokens with `APP_KEY` and reads the connection config from the app cache; a different `.env` or cache store breaks both.
- Check the browser console: a failing `ws://`/`wss://` connect will log `[StreamTerminal]` errors.
- Tokens are single-use with a 300s TTL (`stream.signed_url_ttl`) — a stale tab needs a reload.

**`Address already in use` when starting the server**
- Another process (possibly the original `mwguerra/web-terminal` server) is on port 8090. Run this package on its own port: `php artisan terminal-stream:serve --port=8091` and set `WEB_TERMINAL_STREAM_RATCHET_PORT=8091`.

**Mixed-content / immediate disconnect on HTTPS sites**
- Browsers block `ws://` from `https://` pages. Configure WSS (reverse proxy or `WEB_TERMINAL_STREAM_SSL_CERT`/`_SSL_KEY`) and set `WEB_TERMINAL_STREAM_WEBSOCKET_URL` to the `wss://` endpoint.

**Connection drops behind a reverse proxy**
- Make sure the proxy forwards upgrade headers (`Upgrade`/`Connection`) and has a long read timeout (`proxy_read_timeout 3600s;` in nginx) — idle terminals otherwise get cut.

**SSH login fails**
- The error is thrown server-side; check the `terminal-stream:serve` output. Key content must be the full PEM/OpenSSH private key (not a path). Encrypted keys need the `passphrase` parameter.

**Orphaned shell processes after a server crash**
- The server tracks PTY pids in `storage/web-terminal-stream/pty-sessions.json` and kills stale ones (older than `stream.max_session_lifetime`) on startup and every 60 seconds.

## Testing

```bash
composer test              # Pest, Unit suite
composer test:parallel
composer test:coverage
composer analyse           # PHPStan
composer format            # Laravel Pint
```

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Found a bug? Open an issue on [GitHub](https://github.com/mwguerra/web-terminal-stream/issues).

## License

Open-sourced software licensed under the [MIT License](LICENSE).

## Author

**Marcelo W. Guerra** — [mwguerra.com](https://mwguerra.com)
