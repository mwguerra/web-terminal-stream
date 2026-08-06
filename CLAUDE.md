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

# Integration tests (real SSH/PTY against local Docker containers)
composer test:integration          # boots tests/docker sshd + runs tests/Integration
composer test:integration:linux    # Linux-only PTY tests inside the php container
composer test:integration:down

# E2E (Playwright against the scaffolded Laravel 13 + Filament 5 app)
npm run test:e2e                   # scripts/e2e/run.sh: scaffold + app + WS server + specs

# Static analysis and formatting
composer analyse        # PHPStan
composer format         # Laravel Pint

# Asset builds (required after touching resources/css or resources/js)
npm run build           # Tailwind → resources/dist/web-terminal-stream.css
npm run build:js        # esbuild → resources/dist/stream-terminal.js
npm run build:all

# Release script / benchmark harness
composer release        # bash scripts/release.sh
composer bench          # advisory microbenchmarks (none registered; see docs/benchmarks/README.md)
composer stress         # connection stress harness — 100 concurrent PTYs across latency profiles
```

PHPUnit is configured with `failOnRisky=true` and `failOnWarning=true` — risky/warning tests break the build. Unit tests live under `tests/Unit/` (no `Feature` dir, even though `Pest.php` references one); Docker-backed tests under `tests/Integration/` (skip when Docker is down, hard-fail when `CI`/`WTS_REQUIRE_DOCKER` is set); Playwright specs under `tests/e2e/` against the gitignored `tests/e2e-app/` scaffolded by `scripts/e2e/setup.sh`. Filament is in require-dev (dev-only — production installs still bring their own), so the Filament-dependent tests (Schemas, Plugin) run for real. `composer.lock` is gitignored (library convention — CI and contributors resolve fresh). E2E/integration terminals connect only to the throwaway sshd container in `tests/docker/`, and only ever run readonly commands.

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
- `Schemas\Components\TerminalGrid` — static tiling (`TerminalGrid::make()->panes([...])->paneGap(1)`), a `Filament\Schemas\Components\Grid` subclass rendering panes in a flush CSS grid.
- `Schemas\Components\TerminalWorkspace` — **dynamic tmux-style tiling** (`TerminalWorkspace::make()->ssh(...)->keymap(...)->maxPanes(...)`), mounting `Livewire\StreamWorkspace`, which owns the pane roster + binary split tree (`Data\Layout\LayoutTree`, pure array ops) as `#[Locked]` props. Split/close/zoom/focus/resize run through a configurable prefix-key keymap (`Data\Keymap`, `Enums\PaneAction`, config `workspace.shortcuts`). Panes render as a flat keyed list of absolutely-positioned siblings — NEVER nested DOM — so morphs can't touch live panes; geometry is Alpine-bound. The Alpine component is `Alpine.data('wtsWorkspace')` in the bundle with a static `x-data` attribute: **every `x-*` attribute string on morphed elements must stay render-stable** (inlining `@js($state)` into `x-data` destroys/re-inits the component on every morph).
- `Livewire\StreamTerminal` — thin Livewire wrapper. Issues WebSocket auth tokens (`getWebSocketUrl()`, gated by an optional `useStreamTerminal` Gate), tracks `isConnected`, dispatches `TerminalConnectedEvent`/`TerminalDisconnectedEvent` and direct-logs connections via `TerminalLogger`. Keystrokes never round-trip through Livewire.
- `Livewire\TerminalBuilder` — server-side fluent builder for non-Filament Blade usage (`->render()` mounts the `web-terminal-stream` Livewire component). Identical fluent surface to the schema component (same traits).
- `resources/views/stream-terminal.blade.php` — one large Alpine component: boots ghostty-web (`resources/js/stream-terminal.js`, bundled as global `StreamWeb`), opens the WebSocket, wires data/resize/teardown. The canvas container is `wire:ignore`. Listens for `wts-pane-send` CustomEvents (workspace literal-prefix passthrough).

### 2. WebSocket / PTY server (`src/WebSocket/`)

- `ReactPhpWebSocketServer` — RFC6455 over a `react/socket` loop (started by `Console\Commands\TerminalServeCommand`, `php artisan terminal-stream:serve`). Validates the encrypted single-use token, `Cache::pull`s + `decrypt`s the connection config (`terminal-stream-pty:{sessionId}`), re-checks `ConnectionPolicy`, enforces the connection caps, streams PTY output on a 10ms tick, reaps exited/over-lifetime sessions, records a reliable disconnect log on socket close, and tears down every bridge on `SIGINT`/`SIGTERM` (`shutdown()`).
- `TerminalPtyBridge` — one per connection; local `proc_open` PTY or phpseclib3 SSH `CHANNEL_SHELL`. Handles write/read/resize/terminate.
- `PtySessionRegistry` — JSON pid registry at `storage/web-terminal-stream/`, used to kill orphaned PTYs after crashes (`stream.max_session_lifetime`).
- `Http\Controllers\TerminalWebSocketController` — `POST terminal-stream/ws-token` (route name `web-terminal-stream.ws-token`, web+auth) for custom frontends.

### 3. Fluent config lives in `src/Concerns/`

`Schemas\Components\WebTerminalStream`, `Schemas\Components\TerminalWorkspace`, and `Livewire\TerminalBuilder` share their fluent API by composing traits: `ConfiguresConnection` (connection/local/ssh/workingDirectory — `ssh()` named args are canonical, `privateKey:` not `key:`), `ConfiguresAppearance` (height/title/theme/chrome/frameless/squareCorners + `hasExplicit*()` guards used by containers), `ConfiguresConnectionLifecycle` (connectionBehavior, non-null `getConnectionBehavior()` defaulting to `Always`), `ConfiguresScripts`, `ConfiguresLogging` (named-args `log()`), and `ResolvesTerminalProperties` (the ONE author of the StreamTerminal prop contract). New fluent knobs belong in the matching trait — never duplicated across classes. `EvaluatesOptions` gives the Builder a Closure-aware `evaluate()` matching the one the Schema components inherit from Filament. There are no deprecated aliases — the API had a pre-1.0 clean break (see UPGRADING §4b).

### 4. Security model

There is **no command whitelist** — Stream is a raw PTY byte-pipe and cannot be meaningfully whitelisted. Do not add validation layers that pretend otherwise. The boundaries are: page-level authz; the optional `useStreamTerminal` Gate at token issuance (checked on BOTH the REST `ws-token` route and the Livewire `getWebSocketUrl()` path, plus `connect()`); `Security\ConnectionPolicy` (`security.allow_local` + `security.ssh_allowed_hosts`) enforced at issuance AND re-checked on the server before a PTY starts; `Security\SshHostKeyVerifier` (`security.ssh_host_key.mode`) verifying the SSH host key before authentication; the encrypted expiring single-use token; the connection config **encrypted at rest** in the cache; the `ws-token` route rate limit (`security.token_rate_limit`); the resource caps (`stream.max_connections`/`max_sessions_per_user`/`max_handshake_bytes`); the Origin allow-list; and network reachability of the WS port. Keep those seams intact when changing auth-adjacent code — the `ConnectionPolicy` is enforced on all three issuance/handshake paths on purpose (defense in depth).

### 5. Logging / Filament surface

- `Services\TerminalLogger` writes `Models\TerminalLog` rows (table `terminal_stream_logs`); config `web-terminal-stream.logging.*`, per-terminal overrides via `->log()`. Connection lifecycle only — command-level logging does not exist here.
- `Events\TerminalConnectedEvent` / `TerminalDisconnectedEvent` are dispatched by `StreamTerminal`; `Listeners\TerminalLogListener` is an opt-in subscriber (would duplicate the built-in direct logging if registered).
- `WebTerminalStreamPlugin` — Filament plugin: Terminal page + `TerminalLogResource` (+ Pages/Schemas/Tables/Widgets), navigation config, `components()` whitelist, `withoutTerminalPage()`/`withoutTerminalLogs()` (these subtract from the whitelist too). Use `WebTerminalStreamPlugin::current()` to read runtime config.
- `Filament\Pages\Terminal` — the default demo page; users extend it and override `schema()`, disabling the default via `->withoutTerminalPage()`.

### 6. Artisan Commands (`src/Console/Commands/`)

- `terminal-stream:install` — installer (config, `terminal_stream_logs` migration incl. `--with-tenant`, views, page/resource scaffolding, panel selection). Stubs in `stubs/`.
- `terminal-stream:make-page` — scaffolds a custom Terminal page in the host app.
- `terminal-stream:logs:cleanup` — prunes `terminal_stream_logs` per retention config (`--days`, `--dry-run`).
- `terminal-stream:serve` — starts the ReactPHP WebSocket server (`--host`, `--port`).

## Conventions Specific to This Repo

- **Filament 5 namespaces must be exact** (`Filament\Schemas\*` for layout + `form()`/`schema()`, `Filament\Forms\Components\*` for fields, `Filament\Actions\*` for all actions, `Filament\Tables\*` unchanged). Several classes exist with identical names across v4 and v5 namespaces; the wrong import silently breaks the page.
- **`declare(strict_types=1);`** across `src/` — keep it on new files.
- **Enums everywhere** — `ConnectionType`, `TerminalChrome`, `ConnectionBehavior` (`Manual`/`Auto`/`Always`), `SplitOrientation`, `PaneAction`. Never raw strings for these concepts in new code.
- **No `Feature` test directory** despite the `Pest.php` `uses(...)->in('Feature', 'Unit')` line. Add new tests under `tests/Unit/<AreaMirroringSrc>/`.
- **Assets are committed built files.** `resources/dist/web-terminal-stream.css` and `resources/dist/stream-terminal.js` are consumed by host apps — rebuild and commit them alongside source changes, or the host sees stale styles/JS.
- **Side-by-side isolation is a feature.** Everything host-visible is namespaced `web-terminal-stream`/`terminal-stream` (config file, view/translation namespaces, Livewire alias, artisan commands, route, cache key prefix `terminal-stream-pty:`, storage dir, `terminal_stream_logs` table, `WEB_TERMINAL_STREAM_*` env vars). Never introduce a host-visible identifier that collides with `mwguerra/web-terminal`.
- **Commits use conventional commit messages** (`feat:`, `fix:`, `docs:`, `refactor:`, `test:`, `chore:`). Do not add Claude as co-author.

## Roadmap Direction

The feature area is **terminal tiling**. Both layers have landed:

- **Static**: `TerminalGrid` — flush CSS grid of fixed panes (`panes()`, `columns()`, `paneGap()`, `height()`, behavior forwarding, `:focus-within` ring via `--wts-grid-*` custom properties in `resources/css/index.css`).
- **Dynamic**: `TerminalWorkspace`/`StreamWorkspace` — tmux-style runtime splits with prefix-key shortcuts, drag + keyboard resize, zoom, and directional focus (see Architecture §1 and `docs/architecture.md` §8).

Deferred increments, in likely order:

1. Layout presets (even-horizontal, even-vertical, main-vertical…).
2. Per-user layout persistence (design-ready: the split tree is one `json_encode($tree)` away; a `LayoutRepository` seam is reserved).

## Key Reference Docs in This Repo

- `README.md` — full public API reference (every fluent method, config option, env var, and installation flag).
- `docs/architecture.md` — contributor walkthrough of the stream data path (token auth, PTY bridge, registry lifecycle, teardown rules).
- `UPGRADING.md` — migration guide from `mwguerra/web-terminal` Stream mode to this package.
- `CHANGELOG.md` — version history; starts at the extraction from `mwguerra/web-terminal`.
- `docs/benchmarks/` — advisory benchmark harness docs: `composer bench` (microbench, none registered) and `composer stress` (the connection stress harness).
