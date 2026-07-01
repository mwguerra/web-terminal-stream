# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

`mwguerra/web-terminal-stream` is a **Composer package** (not an application) that provides a Stream-mode web terminal for Laravel + Filament: a full interactive PTY (local shell or SSH) streamed over WebSocket into a ghostty-web WASM canvas in the browser. It ships a Livewire component, a Filament Schema component, a Filament Plugin with a Terminal page and Terminal Logs resource, Artisan commands, and the ReactPHP WebSocket server.

It was extracted from `mwguerra/web-terminal` (its `feature/frameless` branch) with Classic command-by-command mode removed. It is fully renamed so both packages can be installed side-by-side in one app.

- Root namespace: `MWGuerra\WebTerminalStream\` → `src/`
- Tests namespace: `MWGuerra\WebTerminalStream\Tests\` → `tests/`
- Repo: https://github.com/mwguerra/web-terminal-stream
- Requires **PHP 8.3+, Laravel 12–13, Filament 5.x, Livewire 4.x**. (Do not mix in Filament 4 / Livewire 3 patterns.)

## Common Commands

Development runs through Composer and NPM scripts (no `artisan` here — it's a package; tests use Orchestra Testbench):

```bash
# Run the full test suite (Pest on Testbench)
composer test
composer test:parallel

# Coverage (XDEBUG_MODE is set automatically)
composer test:coverage
composer test:coverage:html   # writes to tests/coverage/

# Run a single test file or filter
vendor/bin/pest tests/Unit/Livewire/StreamTerminalTest.php
vendor/bin/pest --filter="dispatches TerminalConnectedEvent"

# Static analysis and formatting
composer analyse        # PHPStan
composer format         # Laravel Pint

# Asset builds (required after touching resources/css or resources/js)
npm run build           # Tailwind → resources/dist/web-terminal-stream.css
npm run build:js        # esbuild → resources/dist/stream-terminal.js
npm run build:all

# Release script / benchmark harness
composer release        # bash scripts/release.sh
composer bench          # advisory; currently no registered benchmarks (see docs/benchmarks/README.md)
```

PHPUnit is configured with `failOnRisky=true` and `failOnWarning=true` — risky/warning tests break the build. Tests live only under `tests/Unit/` (no `Feature` dir, even though `Pest.php` references one). Filament is in require-dev (dev-only — production installs still bring their own), so the Filament-dependent tests (Schemas, Plugin) run for real; no test in the suite skips. `composer.lock` is committed.

## How to Test Changes Against a Real Filament App

This package is typically developed symlinked into a host Laravel app via a Composer path repository. Use **testapp_f5** (the shared Filament 5 sandbox) to drive end-to-end work:

- Host app: `/home/guerra/projects/test_projects/testapp_f5/` → `https://testapp-f5.test`
- Login: `admin@example.com` / `password`
- Point the host's `composer.json` path repository at `../../web-terminal-stream` and require `"mwguerra/web-terminal-stream": "dev-<branch>"`, then `composer update mwguerra/web-terminal-stream` in the host app. (The host may also have `mwguerra/web-terminal` installed — the packages are namespaced to coexist.)
- After editing Blade views or CSS here, run `npm run build` in this repo — the host app loads `resources/dist/web-terminal-stream.css` directly from the package.
- The terminal only works with the WebSocket server running. Start it from the host app: `php artisan terminal-stream:serve` (defaults `127.0.0.1:8090`; use `--port` if the original package's server already holds 8090).

When exercising a real terminal during tests, only use **readonly commands** (`echo`, `ls`, `pwd`, `date`, `whoami`, `hostname`, `cat` on safe files). Never run destructive shell operations from a test page on this workstation.

## Architecture (Big Picture)

One rendering path. Configuration flows Schema component → Livewire props → WebSocket server; bytes flow browser ⇄ WebSocket ⇄ PTY. `docs/architecture.md` is the detailed contributor walkthrough of the data path — read it before touching the WebSocket or teardown code.

### 1. Public API → Livewire component

- `Schemas\Components\WebTerminalStream` — the public fluent API (`WebTerminalStream::make()->local()/->ssh()/->frameless()/...`), a `Filament\Schemas\Components\Livewire` subclass. It always mounts `Livewire\StreamTerminal` and translates the fluent config into its props. Every `make()` gets a unique auto wire:key (`web-terminal-stream-XXXXXXXX`) so multiple terminals per page stay isolated.
- `Schemas\Components\TerminalGrid` — the tiling layout (`TerminalGrid::make()->terminals([...])`), a `Filament\Schemas\Components\Grid` subclass rendering panes in a flush CSS grid (see Roadmap Direction).
- `Livewire\StreamTerminal` — thin Livewire wrapper. Issues WebSocket auth tokens (`getWebSocketUrl()`, gated by an optional `useStreamTerminal` Gate), tracks `isConnected`, dispatches `TerminalConnectedEvent`/`TerminalDisconnectedEvent` and direct-logs connections via `TerminalLogger`. Keystrokes never round-trip through Livewire.
- `Livewire\TerminalBuilder` — server-side fluent builder for non-Filament Blade usage (`->render()` mounts the `web-terminal-stream` Livewire component).
- `resources/views/stream-terminal.blade.php` — one large Alpine component: boots ghostty-web (`resources/js/stream-terminal.js`, bundled as global `StreamWeb`), opens the WebSocket, wires data/resize/teardown. The canvas container is `wire:ignore`.

### 2. WebSocket / PTY server (`src/WebSocket/`)

- `ReactPhpWebSocketServer` — RFC6455 over a `react/socket` loop (started by `Console\Commands\TerminalServeCommand`, `php artisan terminal-stream:serve`). Validates the encrypted single-use token, `Cache::pull`s the connection config (`terminal-stream-pty:{sessionId}`), streams PTY output on a 10ms tick.
- `TerminalPtyBridge` — one per connection; local `proc_open` PTY or phpseclib3 SSH `CHANNEL_SHELL`. Handles write/read/resize/terminate.
- `PtySessionRegistry` — JSON pid registry at `storage/web-terminal-stream/`, used to kill orphaned PTYs after crashes (`stream.max_session_lifetime`).
- `Http\Controllers\TerminalWebSocketController` — `POST terminal-stream/ws-token` (route name `web-terminal-stream.ws-token`, web+auth) for custom frontends.

### 3. Fluent config lives in `src/Concerns/`

`Schemas\Components\WebTerminalStream` and `Livewire\TerminalBuilder` share their fluent API by composing traits: `ConfiguresTerminalAppearance` (height/title/chrome/squareCorners/connectionBehavior), `ConfiguresStreamMode` (streamTheme), `ConfiguresScripts`, `ConfiguresLogging`. New fluent knobs belong in the matching trait — never duplicated across the two classes. `EvaluatesOptions` gives the Builder a Closure-aware `evaluate()` matching the one the Schema component inherits from Filament. `EmitsDeprecationNotices` backs the deprecated aliases (`windowControls()`, `startConnected()`, `autoConnect()`).

### 4. Security model

There is **no command whitelist** — Stream is a raw PTY byte-pipe and cannot be meaningfully whitelisted. Do not add validation layers that pretend otherwise. The boundaries are: page-level authz, the optional `useStreamTerminal` Gate at token issuance, the encrypted expiring single-use token, and network reachability of the WS port. Keep those seams intact when changing auth-adjacent code.

### 5. Logging / Filament surface

- `Services\TerminalLogger` writes `Models\TerminalLog` rows (table `terminal_stream_logs`); config `web-terminal-stream.logging.*`, per-terminal overrides via `->log()`. Connection lifecycle only — command-level logging does not exist here.
- `Events\TerminalConnectedEvent` / `TerminalDisconnectedEvent` are dispatched by `StreamTerminal`; `Listeners\TerminalLogListener` is an opt-in subscriber (would duplicate the built-in direct logging if registered).
- `WebTerminalStreamPlugin` — Filament plugin: Terminal page + `TerminalLogResource` (+ Pages/Schemas/Tables/Widgets), navigation config, `withoutTerminalPage()`/`withoutTerminalLogs()`/`only()`. Use `WebTerminalStreamPlugin::current()` to read runtime config.
- `Filament\Pages\Terminal` — the default demo page; users extend it and override `schema()`, disabling the default via `->withoutTerminalPage()`.

### 6. Artisan Commands (`src/Console/Commands/`)

- `terminal-stream:install` — installer (config, `terminal_stream_logs` migration incl. `--with-tenant`, views, page/resource scaffolding, panel selection). Stubs in `stubs/`.
- `terminal-stream:make-page` — scaffolds a custom Terminal page in the host app.
- `terminal-stream:logs:cleanup` — prunes `terminal_stream_logs` per retention config (`--days`, `--dry-run`).
- `terminal-stream:serve` — starts the ReactPHP WebSocket server (`--host`, `--port`).

## Conventions Specific to This Repo

- **Filament 5 namespaces must be exact** (`Filament\Schemas\*` for layout + `form()`/`schema()`, `Filament\Forms\Components\*` for fields, `Filament\Actions\*` for all actions, `Filament\Tables\*` unchanged). Several classes exist with identical names across v4 and v5 namespaces; the wrong import silently breaks the page.
- **`declare(strict_types=1);`** across `src/` — keep it on new files.
- **Enums everywhere** — `ConnectionType`, `TerminalChrome`, `ConnectionBehavior`. Never raw strings for these concepts in new code.
- **Deprecations** — `EmitsDeprecationNotices` trait + `@deprecated` PHPDoc. The opt-in `web-terminal-stream.deprecations.emit_notices` flag stays off by default. Every deprecation must have its replacement available in the same release.
- **No `Feature` test directory** despite the `Pest.php` `uses(...)->in('Feature', 'Unit')` line. Add new tests under `tests/Unit/<AreaMirroringSrc>/`.
- **Assets are committed built files.** `resources/dist/web-terminal-stream.css` and `resources/dist/stream-terminal.js` are consumed by host apps — rebuild and commit them alongside source changes, or the host sees stale styles/JS.
- **Side-by-side isolation is a feature.** Everything host-visible is namespaced `web-terminal-stream`/`terminal-stream` (config file, view/translation namespaces, Livewire alias, artisan commands, route, cache key prefix `terminal-stream-pty:`, storage dir, `terminal_stream_logs` table, `WEB_TERMINAL_STREAM_*` env vars). Never introduce a host-visible identifier that collides with `mwguerra/web-terminal`.
- **Commits use conventional commit messages** (`feat:`, `fix:`, `docs:`, `refactor:`, `test:`, `chore:`). Do not add Claude as co-author.

## Roadmap Direction

The feature area is **terminal tiling** — tmux-like multi-pane layouts composed of many terminals on one page. The composition layer has landed: `Schemas\Components\TerminalGrid` (extends Filament's Grid) lays out N `WebTerminalStream` panes in a flush CSS grid — auto-applied `frameless()` + `squareCorners()` (overridable per pane), `columns()`/`gap()`/`height()`, grid-level `connectionBehavior()` forwarding, and a CSS `:focus-within` focused-pane ring (styling lives in `resources/css/index.css` via `--wts-grid-*` custom properties).

Deferred increments, in likely order:

1. Drag-to-resize dividers between panes.
2. Keyboard pane navigation (tmux-style prefix keys) + active-pane state in Livewire.
3. Dynamic split/close (add/remove panes at runtime without full re-render).
4. Layout presets (even-horizontal, even-vertical, main-vertical…).

## Key Reference Docs in This Repo

- `README.md` — full public API reference (every fluent method, config option, env var, and installation flag).
- `docs/architecture.md` — contributor walkthrough of the stream data path (token auth, PTY bridge, registry lifecycle, teardown rules).
- `UPGRADING.md` — migration guide from `mwguerra/web-terminal` Stream mode to this package.
- `CHANGELOG.md` — version history; starts at the extraction from `mwguerra/web-terminal`.
- `docs/design/` — the original ghostty/stream design doc and implementation plan.
- `docs/plans/2026-04-21-frameless-branch-plan.md` — the frameless/multi-terminal/benchmarks plan this codebase was extracted from (historical, written pre-extraction against the parent package's API).
- `docs/benchmarks/` — advisory benchmark harness docs (`composer bench`); no benchmarks currently registered.
