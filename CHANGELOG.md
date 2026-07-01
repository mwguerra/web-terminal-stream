# Changelog

All notable changes to `mwguerra/web-terminal-stream` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Initial extraction of the Stream terminal from [`mwguerra/web-terminal`](https://github.com/mwguerra/web-terminal) (its `feature/frameless` branch) as a standalone, Stream-mode-only package.
- Full interactive PTY over WebSocket (ReactPHP server + ghostty-web WASM terminal emulator), for local shells and SSH (password or key auth).
- Filament integration: `WebTerminalStream::make()` schema component, `WebTerminalStreamPlugin` with a Terminal page and a Terminal Logs resource.
- Connection lifecycle auditing: `StreamTerminal` dispatches `TerminalConnectedEvent` / `TerminalDisconnectedEvent` and records them to the `terminal_stream_logs` table via `TerminalLogger`.
- Artisan commands: `terminal-stream:install`, `terminal-stream:serve`, `terminal-stream:make-page`, `terminal-stream:logs:cleanup`.

### Changed

- The WebSocket server's ReactPHP stack (`react/socket`, `react/event-loop`, `ratchet/rfc6455`) moved from `require-dev`/`suggest` to `require` — it is the package's core and now installs automatically.
- **Breaking (vs. `mwguerra/web-terminal`):** Classic command-by-command mode was removed entirely — Stream is the only rendering path. The command whitelist/sanitizer/rate-limit security layer, interactive TUI sessions, mode toggles (`mode()`, `dual()`, `streamTerminal()`, `classicTerminal()`), and the related fluent knobs and config keys do not exist in this package.
- Package renamed to `mwguerra/web-terminal-stream` with namespace `MWGuerra\WebTerminalStream\`, config file `config/web-terminal-stream.php`, view/translation namespace `web-terminal-stream::`, `terminal-stream:*` Artisan commands, `terminal-stream/ws-token` route, and `terminal_stream_logs` table, so it can be installed side-by-side with `mwguerra/web-terminal`.
