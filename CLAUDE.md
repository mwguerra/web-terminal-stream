# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

`mwguerra/web-terminal` is a **Composer package** (not an application) that provides a secure, embeddable web terminal for Laravel + Filament applications. It ships Livewire components, a Filament Schema component, a Filament Plugin with a Terminal page and Terminal Logs resource, Artisan commands, and a WebSocket server for full PTY streaming.

- Root namespace: `MWGuerra\WebTerminal\` → `src/`
- Tests namespace: `MWGuerra\WebTerminal\Tests\` → `tests/`
- Current major version branch (`main`): **v2.x** — requires **PHP 8.3+, Laravel 12–13, Filament 5.x, Livewire 4.x**. (v1.x targeted Laravel 11 / Filament 4 / Livewire 3; do not mix the namespaces/patterns of those stacks here.)

## Common Commands

Development runs through Composer and NPM scripts (no `artisan` — this is a package, it uses Orchestra Testbench for tests):

```bash
# Run the full test suite (Pest on Testbench)
composer test
composer test:parallel

# Coverage (XDEBUG_MODE is set automatically)
composer test:coverage
composer test:coverage:html   # writes to tests/coverage/
composer test:parallel:coverage

# Run a single test file or filter
vendor/bin/pest tests/Unit/Security/CommandValidatorTest.php
vendor/bin/pest --filter=CommandValidator
vendor/bin/pest --filter="allows whitelisted command"

# Static analysis and formatting
composer analyse        # PHPStan (see phpstan config if present)
composer format         # Laravel Pint

# Asset builds (required after touching resources/css or resources/js)
npm run build           # Tailwind → resources/dist/web-terminal.css
npm run build:js        # esbuild → resources/dist/stream-terminal.js (Stream mode)
npm run build:all

# Release script
composer release        # bash scripts/release.sh
```

PHPUnit is configured with `failOnRisky=true` and `failOnWarning=true` — risky/warning tests break the build. Tests live only under `tests/Unit/` (no `Feature` dir, even though `Pest.php` references one).

## How to Test Changes Against a Real Filament App

This package is typically developed symlinked into a host Laravel app via a Composer path repository. Use **testapp_f5** (the shared Filament 5 sandbox) to drive end-to-end work:

- Host app: `/home/guerra/projects/test_projects/testapp_f5/` → `https://testapp-f5.test`
- Login: `admin@example.com` / `password`
- The host's `composer.json` points `mwguerra/web-terminal` at `../../web-terminal`. When working on a non-`main` branch, update it to `"mwguerra/web-terminal": "dev-<branch>"` and run `composer update mwguerra/web-terminal` in the host app.
- After editing Blade views or CSS here, run `npm run build` in this repo — the host app loads `resources/dist/web-terminal.css` directly from the package.
- If running Stream mode end-to-end, start the WebSocket server from the host app: `php artisan terminal:serve` (defaults `127.0.0.1:8090`).

When exercising a real terminal during tests, only use **readonly commands** (`echo`, `ls`, `pwd`, `date`, `whoami`, `hostname`, `cat` on safe files). Never run destructive shell operations from a test page on this workstation.

## Architecture (Big Picture)

The package has **three front-end rendering paths** that share configuration but execute very differently. Most bugs live in the seams between them, so keep the boundary clear.

### 1. Schema Component → Livewire Components

`Schemas\Components\WebTerminal` (a `Filament\Schemas\Components\Livewire` subclass) is the public fluent API (`->local()`, `->ssh()`, `->allowedCommands()`, `->streamTerminal()`, etc.). It does not render the terminal itself — it mounts one of the Livewire components based on mode:

- `Livewire\WebTerminal` — **Classic mode.** Command-by-command execution over Livewire round trips. Handles history, output buffering, interactive sessions via PTY, TUI detection, ANSI→HTML.
- `Livewire\StreamTerminal` — **Stream mode.** Thin Livewire wrapper whose Blade view boots the `ghostty-web` canvas and connects via WebSocket directly to the PHP PTY bridge. There is no Livewire round trip for keystrokes.
- `Livewire\TerminalContainer` — **Dual mode.** Wraps both of the above and renders the Classic/Stream toggle pill.

`Livewire\TerminalBuilder` is a server-side helper that translates the Schema component's configuration into the props each Livewire component expects.

### 2. Connection Handlers (command execution)

- `Contracts\ConnectionHandlerInterface` + `Connections\AbstractConnectionHandler`
- `Connections\LocalConnectionHandler` (symfony/process)
- `Connections\SSHConnectionHandler` (phpseclib)
- `Connections\ConnectionHandlerFactory` — registered as a singleton in the service provider and used by `WebTerminal` to pick the handler from `Data\ConnectionConfig`.

`ConnectionConfig` (in `src/Data/`) is the value object that encapsulates connection details; it has static factories `local()`, `sshWithPassword()`, `sshWithKey()`.

### 3. Interactive Sessions & Stream/PTY

Classic mode's "interactive" commands (artisan tinker, queue:work, etc.) and Stream mode both need long-lived PTY processes. They are managed under `src/Sessions/`:

- `SessionManagerInterface` — the contract.
- `ProcessSessionManager` / `ProcessSession` — symfony/process-based sessions.
- `TmuxSessionManager` — tmux-backed implementation used when available.
- `FileSessionManager` / `SharedSessionData` — cross-request shared state (Livewire is stateless across requests, so session state is written to disk).
- `session-worker.php` — worker script spawned for a session.

`src/WebSocket/` implements Stream mode's server side:

- `ReactPhpWebSocketServer` (started by `TerminalServeCommand` / `php artisan terminal:serve`) speaks RFC6455 via `ratchet/rfc6455` on a `react/socket` loop.
- `TerminalPtyBridge` pairs a WebSocket client with a PTY process and pipes bytes in both directions.
- `PtySessionRegistry` tracks live bridges.
- `Http\Controllers\TerminalWebSocketController` issues short-lived auth tokens (POST `terminal/ws-token`, web+auth middleware) that the browser presents when opening the WS connection.

### 4. Security Layer

All user input passes through `src/Security/` **before** touching a connection handler:

- `CommandValidator` — whitelist match (supports `*` wildcards). Config-driven via `web-terminal.allowed_commands`.
- `CommandSanitizer` — blocks shell metacharacters listed in `web-terminal.security.blocked_characters` unless the per-terminal shell-operator permissions are granted.
- `RateLimiter` — per-user command rate limit (`web-terminal.rate_limit`).
- `CredentialManager` / `SensitiveValue` — wraps SSH passwords / keys so they don't appear in logs or serialized Livewire state.
- `Enums\TerminalPermission` — the enum used by the Schema component's `allow()` method (`InteractiveMode`, `Pipes`, `Redirection`, `Chaining`, `Expansion`, `ShellOperators`, `AllCommands`).

When a new feature touches command input or execution, treat this layer as the single source of truth — don't add parallel validation in Livewire components or handlers.

### 5. Filament Plugin Surface

- `WebTerminalPlugin` — the plugin instance passed to `->plugins([...])`. It owns navigation config and `withoutTerminalPage()` / `withoutTerminalLogs()` / `only()` toggles. Use `WebTerminalPlugin::current()` inside pages/resources to read runtime config.
- `Filament\Pages\Terminal` — the default demo page. Users who want custom allowed commands are expected to extend it and override `schema()`, then disable the default via `->withoutTerminalPage()`.
- `Filament\Resources\TerminalLogResource` (+ Pages, Schemas, Tables, Widgets) — audit UI over the `TerminalLog` model.
- `Models\TerminalLog` — the log record. `Services\TerminalLogger` writes it, `Listeners\TerminalLogListener` subscribes to the events under `src/Events/` (`CommandExecutedEvent`, `TerminalConnectedEvent`, `TerminalDisconnectedEvent`).

### 6. Artisan Commands (`src/Console/Commands/`)

- `terminal:install` — interactive installer (config, migration, views, page/resource scaffolding, panel selection).
- `terminal:make-page` — scaffolds a customizable Terminal page in the host app.
- `terminal:logs:cleanup` — prunes the `terminal_logs` table per retention config.
- `terminal:serve` — starts the ReactPHP WebSocket server for Stream mode.

Installer/scaffolder stubs live in `stubs/`.

## Conventions Specific to This Repo

- **Filament 5 namespaces must be exact** (`Filament\Schemas\*` for layout + `form()`/`schema()`, `Filament\Forms\Components\*` for fields, `Filament\Actions\*` for all actions, `Filament\Tables\*` unchanged). When editing resources/pages here, follow the v5 rules in the user's global `project-v5.md` — several classes exist with identical names across v4 and v5 namespaces, and the wrong import silently breaks the page.
- **Fluent config lives in `src/Concerns/`.** `Schemas\Components\WebTerminal` and `Livewire\TerminalBuilder` share their fluent API by composing traits (`ConfiguresPermissions`, `ConfiguresLogging`, `ConfiguresShellEnvironment`, etc.). When adding a new fluent knob, it belongs in the matching trait — not duplicated across the two classes. `EvaluatesOptions` gives the Builder a Closure-aware `evaluate()` method matching the one the Schema component inherits from Filament.
- **Deprecations.** Use the `EmitsDeprecationNotices` trait + the `@deprecated` PHPDoc tag. The opt-in `web-terminal.deprecations.emit_notices` config flag is off by default; do not turn it on globally. Every deprecation must have a replacement available the same release it's marked — don't deprecate APIs whose replacements don't exist yet.
- **`declare(strict_types=1);`** is used across `src/` — keep it on new files.
- **PHP Attributes for features** — e.g. `#[ValidCommand]`, `#[ValidPath]`, `#[ValidHost]` in `src/Attributes/` and `#[Locked]` on Livewire state that must not round-trip from the client. Prefer these over custom request validation layers.
- **Enums everywhere** — `ConnectionType`, `TerminalMode`, `TerminalPermission`, `OutputType`, `ScriptCommandStatus`. Never use raw strings for these concepts in new code (config keys, tests, Blade, migrations).
- **No `Feature` test directory** despite the `Pest.php` `uses(...)->in('Feature', 'Unit')` line. Add new tests under `tests/Unit/<AreaMirroringSrc>/`.
- **Keep the Livewire component / Schema component / Connection handler seams clean.** Configuration flows Schema → Livewire props → handler; output flows handler → Livewire public arrays → Blade. Don't shortcut across layers.
- **Assets are committed built files.** `resources/dist/web-terminal.css` and `resources/dist/stream-terminal.js` are consumed by host apps — remember to rebuild and commit them alongside source changes, otherwise the host app sees stale styles/JS.
- **Commits use conventional commit messages** (see `git log`: `feat:`, `fix:`, `docs:`, `style:`, `refactor:`, `test:`). Do not add Claude as co-author.

## Key Reference Docs in This Repo

- `README.md` — full public API reference (every fluent method, config option, and installation flag is documented here).
- `UPGRADING.md` — migration guide for major versions, currently tracking the v2.x → v3.0 deprecation runway.
- `CHANGELOG.md` — version history; `v2.0.0` is the Filament 5 upgrade boundary.
- `CONTRIBUTING.md` — contribution workflow.
- `docs/plans/` — historical design docs per feature (shell operators, interactive mode, copy/paste, file session manager, TUI detection, and the active `feature/frameless` branch plan). Read the matching `-design.md` before modifying the corresponding subsystem.
- `docs/benchmarks/` — advisory performance baselines + `composer bench` harness. See `docs/benchmarks/README.md`.
