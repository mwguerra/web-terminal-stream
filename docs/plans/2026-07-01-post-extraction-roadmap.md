# Post-extraction roadmap — 2026-07-01

Status: **approved, in execution**. Four work items, in dependency order. Items 1–3 close the
honesty gaps found during the extraction docs pass (things the README currently documents as
missing or planned). Item 4 opens the package's headline feature area: terminal tiling.

## 1. Promote WebSocket dependencies to `require`

**Problem.** `react/socket`, `react/event-loop`, and `ratchet/rfc6455` live in `require-dev` +
`suggest`, but the WebSocket server is this package's core — every install needs them, and
`terminal-stream:serve` fatals without them.

**Change.** Move all three to `require` in composer.json (keep versions: `react/socket ^1.17`,
`react/event-loop ^1.6`, `ratchet/rfc6455 ^0.4`). Remove them from `suggest`. Update the README
installation section (drop the "install the suggested packages" step). `guzzlehttp/psr7` stays
transitive via ratchet.

**Verify.** `composer update --lock`-level change only; suite green.

## 2. Origin validation on the WebSocket handshake

**Problem.** The extraction removed the `stream.allowed_origins` config key because nothing
consumed it — meaning the WS server accepts a handshake from any Origin. The single-use token is
the primary auth gate, but a malicious page in a logged-in user's browser could fetch a token via
the user's session and open a socket (CSRF-shaped hole).

**Design.**
- Re-add `stream.allowed_origins` to `config/web-terminal-stream.php`, default `[env('APP_URL', 'http://localhost')]`.
- Enforce in `ReactPhpWebSocketServer` during the handshake, before the token is consumed:
  - If the request carries an `Origin` header: normalize (scheme + host + optional port, no path,
    case-insensitive host) and require an exact match against the normalized allowlist. No match →
    reject the handshake with `403` and close; log at warning level.
  - If no `Origin` header (non-browser client, tests): allow — the token remains the auth gate.
    Browsers always send `Origin` on WebSocket upgrades, so this does not weaken browser-facing
    CSRF protection.
- No wildcard support in the MVP; a literal `'*'` entry disables the check (documented escape
  hatch for reverse-proxy setups that strip/rewrite Origin).

**Verify.** Unit tests for the origin matcher (allowed, denied, missing header, port mismatch,
case differences, `'*'`); suite green. README + docs/architecture.md security sections updated to
describe the check.

## 3. Manual-connect UI (make `ConnectionBehavior` real)

**Problem.** `connectionBehavior()`, `startConnected()`, `autoConnect()` are documented but the
stream Blade view always auto-connects via IntersectionObserver and renders no connect/disconnect
control. Only `AutoHidden` matches reality.

**Design.** Respect `ConnectionBehavior::toFlags()` end-to-end:
- `Manual` (default): do **not** open the WebSocket on mount. Render a centered "Connect"
  affordance in the terminal body (button styled to the theme). On click → fetch token, open WS,
  swap to the live canvas. After connect, show a disconnect control.
- `AutoWithButton`: current auto-connect on visibility, plus a visible disconnect/reconnect
  control.
- `AutoHidden`: exactly today's behavior (auto-connect, no controls).
- Control placement follows `TerminalChrome`: header action button when the header exists,
  floating overlay button when `frameless()`.
- Disconnect closes the WS cleanly (client-initiated close code), releases the PTY per the
  existing registry grace-period rules, and returns to the Connect affordance.
- Livewire/Alpine: the behavior flags already reach the view as props; the work is in the Alpine
  component (`connect()`/`disconnect()` actions, `state` machine: `idle → connecting → connected →
  disconnected`) and the Blade chrome. `StreamTerminal::connect()/disconnect()` server methods
  already exist and fire the lifecycle events — wire the UI to them so logging stays truthful
  (no "connected" log rows for panes never opened).

**Verify.** Pest coverage for prop plumbing per behavior case; JS rebuilt (`npm run build:all`),
dist committed. README fluent-API table updated: remove the "only AutoHidden works" honesty note,
document the three behaviors as real. UPGRADING note: default behavior changes from implicit
auto-connect to `Manual` — hosts that relied on auto-connect must set
`->connectionBehavior(ConnectionBehavior::AutoHidden)` (or we keep `AutoHidden` as the schema
component's default to preserve extraction-era behavior — decision: **keep `AutoHidden` as
default**, no breaking change; `Manual` is opt-in).

## 4. Terminal tiling — first increment

**Prior art.** The frameless branch plan (2026-04-21) decided "layout composition stays external"
— correct for a dual-mode general package. This package is *dedicated* to stream terminals and
tiling is its roadmap headline, so composition moves in-package. `frameless()` +
`squareCorners()` were already validated in a 2×2 tmux-style layout under Playwright; what's
missing is the composition layer.

**MVP scope (this increment).**
- New schema component `Schemas\Components\TerminalGrid`: accepts N `WebTerminalStream` children
  and lays them out in a flush CSS grid.
  - `TerminalGrid::make()->terminals([...])` — children are regular `WebTerminalStream`
    instances; the grid auto-applies `frameless()` + `squareCorners()` to each (overridable).
  - `->columns(int|array $columns)` — Filament-style responsive columns, default 2.
  - `->gap(int $px = 0)` — default 0 for the flush tmux look; a 1px gap renders as pane dividers
    via the grid container background.
  - `->height(string)` — grid height; rows share it equally (`grid-auto-rows: 1fr`).
  - Key isolation: reuse the existing per-terminal wire-key uniqueness (`web-terminal-stream-*`)
    — already solved by the multi-terminal isolation work.
- Focused-pane indication: a subtle ring on the pane whose hidden textarea owns keyboard focus
  (CSS `:focus-within` on the pane wrapper — no JS focus tracking needed for MVP).
- Works with item 3: panes default to the grid's `connectionBehavior()` (grid-level setter
  forwards to children), so a dashboard of `Manual` panes doesn't spawn N PTYs on load.

**Explicitly deferred (next increments, in likely order).**
1. Drag-to-resize dividers between panes.
2. Keyboard pane navigation (tmux-style prefix keys) + active-pane state in Livewire.
3. Dynamic split/close (add/remove panes at runtime without full re-render).
4. Layout presets (even-horizontal, even-vertical, main-vertical…).

**Verify.** Pest tests: grid renders N children, auto-applies frameless/squareCorners, respects
overrides, columns/gap/height props, connectionBehavior forwarding, unique keys. README gets a
"Tiled layouts" section built around `TerminalGrid` (replacing the manual Grid recipe);
CLAUDE.md roadmap section updated; CHANGELOG entries for all four items.

## Sequencing

1 → 2 → 3 → 4 (single branch of work on `main`, one conventional commit per item; docs updated
within each item's commit so no item leaves README lying).
