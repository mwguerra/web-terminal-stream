# 05 — Frontend: morph-safety, teardown, assets

**Area key:** `frontend`

## Summary

The browser layer is mature and carefully engineered. Morph-safety is handled correctly on all three surfaces: TerminalWorkspace and TerminalDashboard use a static `x-data="wtsWorkspace"/"wtsDashboard"` string with initial state read from `$wire` inside `init()` (terminal-workspace.js:305, :221), registered once via `Alpine.data()` with an idempotent guard on both `alpine:init` and immediate paths (stream-terminal.js:10-19). StreamTerminal's inline `x-data` only interpolates render-stable config (theme, connectionBehavior, fontSize) and routes mutable state through `$wire.entangle('isConnected')`, so morphs never re-init it. Flat keyed pane DOM (`wire:key` per pane) lets Livewire skip matched children; canvas and divider layer are `wire:ignore`; dividers use the closure-captured `rootEl` not `$el` (terminal-workspace.js:284,620,630). Teardown is thorough — a single bound `_teardownHandler` on beforeunload/pagehide/livewire:navigating, idempotent `destroy()` disposing WS/disposables/Terminal, and `replaceChildren()` stale-buffer guard. Keyboard interception uses a document capture-phase listener above ghostty's textarea with a correct tmux prefix state machine and `stopImmediatePropagation`. The one concrete defect is a stale committed CSS asset: commit abba57d changed a Blade view but did not rebuild `resources/dist/web-terminal-stream.css`, which host apps load directly — the fix silently does not ship. The JS bundle is in sync. XSS surface is developer-controlled config only (themes/titles from schema config, all Blade-escaped) with no user-input path.

## What exists and works

- Render-stable morph-safe Alpine registration: static x-data name + state read from $wire in init() for workspace (terminal-workspace.js:305) and dashboard (:221); idempotent Alpine.data registration guard (stream-terminal.js:10-19)
- StreamTerminal inline x-data interpolates only stable config; mutable isConnected via $wire.entangle so morphs preserve ws/terminal state (stream-terminal.blade.php:10-13)
- Flat keyed pane DOM (wire:key per pane) so Livewire skips matched children; canvas container and divider layer are wire:ignore (terminal-workspace.blade.php:36,62)
- Single-bound _teardownHandler on beforeunload/pagehide/livewire:navigating with idempotent destroy() disposing WS, dataDisposable/resizeDisposable, and Terminal (stream-terminal.blade.php:260-314)
- replaceChildren() stale-buffer guard on the wire:ignore canvas container before re-opening ghostty (stream-terminal.blade.php:44-46)
- Divider drag uses closure-captured rootEl (not contextual $el) for pointer->ratio geometry, with pointer capture and rAF-coalesced moves (terminal-workspace.js:284,620-643)
- Document capture-phase keydown above ghostty's textarea with tmux prefix state machine, arm/disarm timeout, and stopImmediatePropagation (terminal-workspace.js:322-431)
- Workspace destroy() removes keydown+focusin listeners and clears prefix/ratio timers (terminal-workspace.js:334-339)
- computeRects / findNeighbor / findResizeSplit / withRatio kept DOM-free and testable; geometry Alpine-bound never server-rendered
- JS dist bundle (resources/dist/stream-terminal.js) verified byte-identical to a fresh esbuild of current source

## Gaps (missing functionality)

| Gap | Severity | Detail |
|---|:--:|---|
| No CI/test guard that committed dist assets match a fresh build | medium | The stale-CSS flaw slipped in because nothing verifies resources/dist/* is in sync with source. The E2E workflow installs node and runs Playwright but never rebuilds assets (scripts/e2e/setup.sh only composer-creates the host app; no `npm run build:all`), so the host app consumes the committed dist — meaning stale assets are exercised but never diffed against fresh output. A single CI step (`npm run build:all && git diff --exit-code resources/dist`) would have failed on abba57d. Given CLAUDE.md's rule that dist is committed and host-consumed, this freshness check is the missing safeguard. |
| themeCss / theme values interpolated into inline style/JS without a documented allow-list | low | Theme values flow unvalidated into inline `style="..."` (stream-terminal.blade.php:6,547,562; terminal-workspace.blade.php:13; terminal-dashboard.blade.php:30) and into the x-data JS object (theme via json_encode, stream-terminal.blade.php:52). Blade's HTML-escaping prevents attribute breakout and there is no end-user input path (themes are developer config / registered presets), so this is not an XSS vulnerability today. But nothing constrains a theme property/value to a CSS-safe grammar, so a future feature that lets end users supply theme colors would open CSS-injection. Worth a note, not a current bug. |

## Flaws (implemented but wrong/risky)

Each critical/high flaw was handed to an independent skeptic instructed to refute it.

### Committed dist CSS is stale — frameless info-panel fix (abba57d) does not ship
- **Severity:** medium — **Verdict:** ⚪ unverified
- **Files:** resources/dist/web-terminal-stream.css, resources/views/stream-terminal.blade.php
- **Detail:** Commit abba57d added the frameless title-bar fill div using the Tailwind class `top-0` (stream-terminal.blade.php:504: `class="absolute inset-x-0 top-0 z-10 ..."`) but did not rebuild resources/dist/web-terminal-stream.css. A fresh `npm run build` produces a CSS file that differs from the committed one by exactly one rule — `.top-0{top:calc(var(--spacing)*0)}` — which is absent from the shipped file. CLAUDE.md states host apps load resources/dist/web-terminal-stream.css directly, so in every consumer the fill strip resolves to `top:auto` (its static flow position, below the header region) instead of `top:0`, reproducing the exact frameless-strip visual bug that abba57d was written to fix. The commit's regression tests only assert Blade markup (data-wts-frameless-fill), not computed style, so nothing caught it.
- **Evidence:** grep -c '\.top-0{' resources/dist/web-terminal-stream.css => 0; same grep on a fresh build => 1. diff of committed vs fresh CSS shows only `66a67 > .top-0{top:calc(var(--spacing)*0)}`. Blade el at resources/views/stream-terminal.blade.php:504 depends on that class. JS bundle diff is clean (in sync).

### Manual-behavior IntersectionObserver is never disconnected
- **Severity:** low — **Verdict:** ⚪ unverified
- **Files:** resources/views/stream-terminal.blade.php
- **Detail:** In init() an IntersectionObserver is created as a local const observing this.$el. For non-manual behavior it calls observer.disconnect() after the first intersection, but for behavior==='manual' the callback `return`s early WITHOUT disconnecting, and destroy() never references the observer (it isn't stored on `this`). The observer therefore outlives explicit teardown for manual panes. Keeping it alive for later refit is partly intentional (the else-if branch refits on re-intersection once fitAddon exists), but on livewire:navigating destroy() the observer and its closure over `this` are only reclaimed by GC, not proactively released. Low impact: a small transient leak per manual terminal on SPA navigation.
- **Evidence:** stream-terminal.blade.php:297-313 creates `const observer = new IntersectionObserver(...)`; manual branch at :302 returns without disconnect; destroy() at :260-283 never disconnects it.

### Workspace destroy() drops the pending debounced ratio sync
- **Severity:** low — **Verdict:** ⚪ unverified
- **Files:** resources/js/terminal-workspace.js
- **Detail:** queueRatioSync debounces the $wire.updateRatios call by 400ms (terminal-workspace.js:669-674). destroy() clears _ratioSyncTimer (:338) but does not flush _pendingRatios first. If the user finishes a divider drag and immediately navigates away (or the component is torn down) within that 400ms window, the last drag ratio is never persisted server-side, so the layout silently reverts on the next visit. Edge-case UX only.
- **Evidence:** terminal-workspace.js:659-675 (debounce) and :334-339 destroy() clears the timer without invoking this.$wire.updateRatios(this._pendingRatios).

### StreamTerminal Escape keydown listener on $el is never removed
- **Severity:** low — **Verdict:** ⚪ unverified
- **Files:** resources/views/stream-terminal.blade.php
- **Detail:** initStream() adds a capture-phase keydown listener on this.$el to forward Escape as an ESC byte (stream-terminal.blade.php:70-78), but destroy() never removes it and no reference is retained to do so. In the normal flow initStream() runs at most once per component (terminal is not nulled on disconnect, so startSession()'s `if (!this.terminal)` gate prevents re-entry), and the element is GC'd on navigation, so it does not leak or duplicate in practice — but it is the one teardown-adjacent listener not covered by the otherwise-rigorous single-handler teardown discipline, and would duplicate if initStream were ever reached twice.
- **Evidence:** stream-terminal.blade.php:70 addEventListener on this.$el with no matching removeEventListener in destroy() (:260-283); teardown for window/document listeners is handled but this element listener is not.

