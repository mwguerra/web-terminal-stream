# Open Questions for the Owner

Decisions the audit could not make for you, aggregated across areas.

### Release / versioning state (must resolve — it changes the whole risk posture)
- There are **9 local git tags** (`v0.1.0`…`v2.3.0`) but **none are pushed to origin**, the CHANGELOG is entirely `## [Unreleased]`, and the README compat table says "1.x". Are those local tags intentional (a botched/aborted release?) or leftovers to delete? **Is the package published on Packagist / installed anywhere today?** The audit assumed "unreleased, breaking changes free" — if that is wrong, the P0 security items are actively exposed and breaking changes need a deprecation path. This is the first decision to make.

### ReactPHP WebSocket server: concurrency & leaks
- Is the server ever intended to run behind a reverse proxy that terminates TLS and strips Origin? If so the unauthenticated handshake-buffer growth (no size cap) is directly internet-reachable.
- Is a guest/unauthenticated terminal ever a supported configuration? If auth()->id() can be null, the token carries userId=null which is passed into TerminalPtyBridge's `int $userId` constructor and would TypeError inside the unguarded handshake path (same crash class as the SSH-auth-failure flaw).

### Cross-process token/cache/config correctness
- Should terminal-stream:serve hard-fail (not just warn) when config('cache.default') resolves to the array driver, or only warn?
- Is a dedicated cache-store config key (web-terminal-stream.stream.cache_store) wanted so the handoff can pin a known-shared store independent of the app's default?

### Fluent component API + Livewire props
- Is TerminalDashboard's full-chrome default for source panes intentional (i.e. dashboards are meant to show framed terminals), or should it auto-apply frameless()/squareCorners() like Grid and Workspace for a consistent tiled look?
- Is the 4-pane hard ceiling on TerminalDashboard::maxOpen a deliberate product constraint, or an artifact that should track a configurable dashboard.max_open like workspace.max_panes?
- Should TerminalWorkspace::defaultPane templates inherit the workspace's theme/font/connection by default (with the closure overriding), or is the fully-fresh builder the intended contract?

### Layout engine: LayoutTree + Keymap
- Is validate()/sameTopology() intended purely as public API for external tree builders, or should the package call validate() somewhere (e.g. on config-provided initial layouts) to justify its 'untrusted tree' docblock?
- Should fromArray/prefix(null) actively strip or warn about single-character bindings, since a null prefix + default tmux bindings renders the terminal unusable for ordinary typing?

### Logging, events, Filament Logs, tenancy
- Is TerminalLogResource intended to be registered inside multi-tenant Filament panels? If yes, the missing tenant scoping needs getEloquentQuery()/ownership-relationship wiring before 1.0.
- Should the command/output/error event types, columns, filters, Infolist sections and StatsOverview tiles be removed entirely (Stream is connection-only), or is command-level logging a planned future feature that justifies keeping the dormant UI?

### Config, provider, packaging, deps
- Does the parent package mwguerra/web-terminal register its stream JS FilamentAsset under the id 'stream-terminal'? If so, the side-by-side JS asset collision is confirmed high, not medium.
- Is PHP 8.2 an intended support target (composer ^8.2) or a documentation slip (docs say 8.3+)? The answer decides whether to raise the constraint or add 8.2 to CI.

### Test suite structure, coverage, CI
- Is PHP 8.2 actually supported (composer.json ^8.2) or is 8.3 the real floor (CLAUDE.md says 8.3+)? The CI matrix tests neither 8.2 nor the 8.5 you develop on.
- Is the intended fix for the e2e-app OOM to relocate the host app out of tests/, or to configure Pest's dataset roots? The current memory_limit=-1 approach still forces a 90s+ scan of 16k files on every run.
- Should composer analyse ship phpstan (add require-dev + phpstan.neon + a CI step) or be removed from both composer.json and CLAUDE.md?

