# Changelog

All notable changes to `mwguerra/web-terminal-stream` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Security

- The WebSocket server now validates the `Origin` header of every browser handshake against the (re-added) `stream.allowed_origins` config key (default `[env('APP_URL', 'http://localhost')]`). Disallowed origins are rejected with HTTP 403 — and logged at warning level — before the single-use auth token is consumed. Requests without an `Origin` header (non-browser clients) still pass through to token auth; a literal `'*'` entry (or an empty list) disables the check.

### Added

- `Schemas\Components\TerminalGrid` — first increment of the tiling layer. Lays out N `WebTerminalStream` panes in a flush CSS grid: `TerminalGrid::make()->terminals([...])` with Filament-style responsive `columns()` (default 2), `gap(int $px = 0)` (positive gaps render as pane dividers via the container background), `height(string)` with equally shared rows, auto-applied `frameless()` + `squareCorners()` per pane (a pane's explicit setting wins), grid-level `connectionBehavior()` forwarded to panes without their own, a CSS `:focus-within` focused-pane ring, and per-pane wire:key isolation. Manual composition of frameless terminals in your own Grid still works.
- `connectionBehavior()` is now fully implemented instead of merely accepted. `ConnectionBehavior::Manual` renders a themed, centered Connect affordance and opens the WebSocket (and PTY) only on click — never-opened panes cost no server process and write no connection log rows. `AutoWithButton` auto-connects and adds a connect/disconnect toggle (header action, or floating overlay control when `frameless()`), with a Reconnect affordance after disconnect. The schema component default remains `AutoHidden` (auto-connect, no controls), so existing code is unaffected. Disconnect closes the WebSocket with a clean client close (code 1000).
- Initial extraction of the Stream terminal from [`mwguerra/web-terminal`](https://github.com/mwguerra/web-terminal) (its `feature/frameless` branch) as a standalone, Stream-mode-only package.
- Full interactive PTY over WebSocket (ReactPHP server + ghostty-web WASM terminal emulator), for local shells and SSH (password or key auth).
- Filament integration: `WebTerminalStream::make()` schema component, `WebTerminalStreamPlugin` with a Terminal page and a Terminal Logs resource.
- Connection lifecycle auditing: `StreamTerminal` dispatches `TerminalConnectedEvent` / `TerminalDisconnectedEvent` and records them to the `terminal_stream_logs` table via `TerminalLogger`.
- Artisan commands: `terminal-stream:install`, `terminal-stream:serve`, `terminal-stream:make-page`, `terminal-stream:logs:cleanup`.

### Fixed

- `WebTerminalStream::ssh()` named its first parameter `$config`, so the documented named-parameter form (`->ssh(host: '…', username: '…')`) failed with "Unknown named parameter". The parameter is now `$host`; positional array/Closure usage is unchanged.

### Changed

- `filament/filament` (`^5.0`) added to `require-dev` (dev-only — the production `suggest` is unchanged) so the Filament-dependent tests (schema components, plugin) run for real instead of self-skipping. The previously skipped plugin tests were rewritten against the actual plugin API; the skipping had hidden stale assertions of Classic-era methods (`isEnabled()`, `disabled()`, `allowedCommands()`) that never existed in this package. `composer.lock` is now committed.
- The WebSocket server's ReactPHP stack (`react/socket`, `react/event-loop`, `ratchet/rfc6455`) moved from `require-dev`/`suggest` to `require` — it is the package's core and now installs automatically.
- **Breaking (vs. `mwguerra/web-terminal`):** Classic command-by-command mode was removed entirely — Stream is the only rendering path. The command whitelist/sanitizer/rate-limit security layer, interactive TUI sessions, mode toggles (`mode()`, `dual()`, `streamTerminal()`, `classicTerminal()`), and the related fluent knobs and config keys do not exist in this package.
- Package renamed to `mwguerra/web-terminal-stream` with namespace `MWGuerra\WebTerminalStream\`, config file `config/web-terminal-stream.php`, view/translation namespace `web-terminal-stream::`, `terminal-stream:*` Artisan commands, `terminal-stream/ws-token` route, and `terminal_stream_logs` table, so it can be installed side-by-side with `mwguerra/web-terminal`.
