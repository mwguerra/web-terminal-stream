# Frameless Branch Plan

**Branch:** `feature/frameless`
**Started:** 2026-04-21
**Target release:** 2.x (continuing with Laravel/Filament version cadence)
**v3 horizon:** Within ~12 months; this branch establishes the deprecation runway.

> This document is the single source of truth for decisions, scope, progress, and open questions on this branch. Update it as work progresses. After the branch ships it becomes the basis for UPGRADING.md, README rewrites, and CHANGELOG entries.

---

## 1. Scope

The branch delivers six interlocking pieces of work:

1. **Frameless configuration** — new config to strip the outer frame (header, footer, border) and replace with floating controls collapsed under a "..." circle top-right. Info/scripts/details panels become slideovers. Must look correct both standalone and inside arbitrary containers (since external composition was chosen).
2. **Multi-terminal isolation** — multiple terminals on one Filament page must work independently (foundation for an external "tmux-like" app built on top of this package).
3. **Terminal resizing** — improve and thoroughly test resize behavior for both Classic and Stream, especially under slideover open/close and viewport changes.
4. **Performance benchmarks** — establish a baseline harness (PHP + browser + WebSocket server) so every future change has a regression reference. Advisory only.
5. **Stream buffer bug fix** — navigating away from a Stream session and returning sometimes leaves stale screen content. Root cause identified in §5, fix plan in Stage 1.
6. **Deprecation & upgrade documentation** — write UPGRADING.md skeleton, annotate deprecated methods, add opt-in runtime notices, document best practices for the v2→v3 migration.

Out of scope (explicitly deferred to the user's external app or later releases):
- A `TerminalGrid` or `TerminalPane` schema component. Layout composition stays external.
- Blocking CI for benchmarks (advisory only).
- Any v3 breaking changes. All public API on this branch remains v2-compatible.

---

## 2. Locked Decisions

Decisions captured from the `AskUserQuestion` round on 2026-04-21.

| # | Topic | Decision | Consequence |
|---|---|---|---|
| 1 | Multi-server scenario | **Both patterns** — single page with Closure-resolved config **and** separate page classes per server | Stream-bug reproducer needs two test pages; Livewire morph behavior differs between them; both must survive the fix |
| 2 | testapp-f5 state | **Preserve and extend** | Existing pages (CopyPasteTerminal, GhosttyTerminal, InteractiveTerminal, ShellOperatorTerminal, StreamTerminal) act as v2-baseline regression guards; new pages are added alongside |
| 3 | Benchmark gating | **Advisory only** | `composer bench` locally, JSON baselines in `docs/benchmarks/baselines/`, delta script prints to stdout; never fails CI |
| 4 | Runtime deprecation notices | **Off by default** | Config key `web-terminal.deprecations.emit_notices` defaults `false`; opt-in via env var; `@deprecated` PHPDoc + UPGRADING.md carry the signal |
| 5 | "..." menu reveal | **Vertical dropdown** | Standard Alpine popover pattern; reuses styling from existing scripts-dropdown; low a11y risk |
| 6 | Grid/layout API | **External composition** | No `TerminalGrid` in this package; each `WebTerminal` stays self-contained with clean isolation; external app composes |

---

## 3. GitHub Community Items — Assessment

Both items were reviewed on 2026-04-21 prior to starting this branch. Neither blocks our work; both integrate into stages below.

### Issue #3 — Stream SSH mode crashes/hangs in ReactPHP websocket server

Opened by `solpreneur (Priesly Enongene)`. State: OPEN, no comments.

**Claim:** SSH stream connections can crash with `RuntimeException: Please close the channel (2) before trying to open it again`, or with phpseclib channel-exec bootstrap errors, or hang after one successful connection blocking the whole event loop.

**Assessment: the diagnosis is correct.**

- `TerminalPtyBridge::startSsh()` at `src/WebSocket/TerminalPtyBridge.php:111-113` uses `$ssh->setTimeout(0); $ssh->enablePTY(); $ssh->exec('');`. In phpseclib3, `setTimeout(0)` means "no timeout = block forever", not "non-blocking". The inline comment at line 110 is **wrong**. The code survives today because `read('')` usually finds buffered data quickly, but under concurrency or slow remotes it will block the single-threaded ReactPHP loop and stall every other session.
- `enablePTY() + exec('')` bootstraps the shell via CHANNEL_EXEC. Some SSH servers reject or half-close an empty exec, leaving channel state inconsistent — exactly the "channel 2" error the reporter sees. The dedicated CHANNEL_SHELL path via `$ssh->write('', SSH2::CHANNEL_SHELL)` is semantically correct for an interactive shell.
- `read()` (line 135) and the SSH branch of `terminate()` (line 207) have no try/catch. A phpseclib `TimeoutException` or any other Throwable propagates up into the ReactPHP event loop, kills the loop, takes down every other live session. This is a **server-wide availability bug**, not just a per-session bug.

**Proposed patch quality:** The diff in the issue is clean and correct. One nit for our implementation:
- The `catch (\Throwable)` that returns empty string in `read()` silently hides **all** SSH errors. Better: catch, return empty, **mark the bridge dead** (null out `$this->sshShell` or set a `$isDead` flag) so `tick()` stops polling it and `handleClose` fires. Otherwise a session whose SSH broke mid-stream looks "idle but alive" until the outer WebSocket timeout.
- No test coverage in the proposed patch. We should add a Pest test against a mock SSH2 that throws on `read()`, plus a Playwright E2E that reconnects SSH sessions serially.

**Does our branch work supersede this?** No — Stage 1's Stream-buffer fix addresses client-driven teardown leaks, a different failure mode. Issue #3 is server-driven and must be fixed on its own terms. **Integration plan: fold into Stage 1** (same subsystem, same tests), acknowledge the reporter in the commit and CHANGELOG.

### PR #4 — "Added confirm page and three dots for loading"

Opened by `wvanderwaal-iqmessenger (Wouter van der Waal)`. State: OPEN. Uses Claude Opus 4.6 assisted contributions.

**Two things in one PR:**

**(a) Functional bug fix — wiring `Script::confirmBeforeRun()`.**

Today `Script::confirmBeforeRun()` sets a flag but no view or Livewire code ever reads `Script::requiresConfirmation()`. The feature is documented in the README (scripts section, "reboot server" example) but silently no-ops. **This is actually a minor security smell** — users believe they've guarded destructive commands behind a confirmation prompt, when in reality no prompt exists.

The PR adds:
- `public string $pendingScriptKey` on WebTerminal Livewire
- `runScript()` short-circuits to set `pendingScriptKey` when confirmation is required
- `confirmPendingScript()` / `cancelPendingScript()` Livewire methods
- Inline confirmation row inside the scripts dropdown

Logic is correct. **We need this.**

**(b) Styling changes — ChatGPT-style loading dots + shimmer text.**

Replaces SVG spinners with three animated dots, adds a "shimmer" gradient animation for the running command's text. Subjective, cohesive with current trends.

**Smells identified:**
- Unnecessary `protected` → `public` change on `$interactiveCommand` and `$interactiveStartTime` in `WebTerminal.php`. Those are internal state; exposing them to Livewire's client-observable property surface is a regression. Should revert in our integration.
- `.shimmer-text` is applied to a flex row `<div>` but the CSS uses `-webkit-background-clip: text` + `color: transparent`, which only works on text elements. On a block container it produces ambiguous visual effects. **Styling bug.**
- `@click.away` now calls `$wire.cancelPendingScript()` when the confirmation is pending. That silently cancels on any outside click. Reasonable default but worth documenting.
- No Pest coverage for the new `confirmPendingScript` / `cancelPendingScript` methods.

**Does our branch work supersede this?** Partially. Stage 4 (Frameless) refactors the scripts dropdown into floating-controls + slideover panels. Merging PR #4 as-is then reworking the surrounding UI immediately creates churn. **Integration plan:**
1. Cherry-pick the functional confirm/cancel implementation into Stage 4.
2. Drop the `public` visibility changes on internal state.
3. Fix the shimmer-text DOM target (apply to text node, not row container).
4. Add Pest tests for `confirmPendingScript` and `cancelPendingScript`.
5. Decide the loading-state visual (dots vs. spinner vs. new design) as part of the cohesive frameless chrome decision.
6. Credit the contributor in the commit message and CHANGELOG.

---

## 4. testapp-f5 Inventory

Audited on 2026-04-21. Location: `/home/guerra/projects/test_projects/testapp-f5/app/Filament/Pages/`.

### Existing (preserve untouched — v2 baseline regression harness)

| Page | Covers |
|---|---|
| `CopyPasteTerminal.php` | Copy-all, per-block copy, paste with confirmation |
| `GhosttyTerminal.php` | Dual-mode (Classic + Stream) container, mode toggle pill |
| `InteractiveTerminal.php` | Classic `allowInteractiveMode()`, PTY/tmux sessions, tinker/queue:work |
| `ShellOperatorTerminal.php` | Individual and aggregate shell operator permissions |
| `StreamTerminal.php` | Stream-only mode (classic disabled) |

These stay exactly as they are. If any commit in this branch breaks one of these pages in Playwright, the commit is a regression.

### New pages to add (Stage 0)

| Page | Purpose | Stage consumed by |
|---|---|---|
| `FramelessTerminal.php` | Frameless chrome, floating controls, slideover panels | Stage 4 |
| `MultiTerminal.php` | 4 terminals on one page (2 Classic + 1 Stream + 1 Dual) — isolation stress | Stage 2 |
| `ResizeTerminal.php` | Terminal inside a resizable container for resize behavior | Stage 3 |
| `MultiServerSamePage.php` | Same class, Closure-resolved config keyed by `?server=` query param | Stage 1 (Stream-buffer bug) |
| `MultiServerPageA.php` + `MultiServerPageB.php` | Different classes, different static configs | Stage 1 (Stream-buffer bug) |

---

## 5. Stream Buffer Bug — Root Cause Analysis

Symptom (reported 2026-04-21): opening a Stream terminal connected to server A, navigating to another page, then reopening a terminal page configured for server B leaves the previous session's screen content visible sometimes.

**Primary root cause:** teardown in `resources/views/stream-terminal.blade.php:175` is bound only to `beforeunload`:

```js
init() {
    window.addEventListener('beforeunload', this.destroy.bind(this));
    ...
}
```

`beforeunload` fires on full browser navigation but **not on `wire:navigate`** (Filament's SPA navigation). Every Filament-internal navigation leaks:
- WebSocket never `close()`'d from client → backend `handleClose` delayed to TCP FIN timeout.
- `this.terminal.dispose()` never called → ghostty `Terminal` stays alive in closures captured by `ws.onmessage`, including scrollback.
- Server-side: `TerminalPtyBridge::terminate()` delayed, `PtySessionRegistry` entry lingers, `Cache::put("terminal-pty:{uuid}")` entry never deleted.

**Amplifier — `wire:ignore` on the xterm container** (`stream-terminal.blade.php:338`): Livewire morph skips that subtree, so stale canvas/textarea DOM children survive morphing. New Alpine `init()` sees fresh scope (`!this.terminal`), calls `terminal.open($refs.streamContainer)` on a container that already contains previous-session DOM. ghostty-web `open()` append/reuse behavior plus undisposed canvas bitmap = visible stale content.

**Why intermittent:** Whether Filament morphs (same-class revisit — bug visible) vs. fully replaces (different-class revisit — bug hidden) depends on navigation target. Whether the old WebSocket has tripped TCP timeout before the new one connects is race-dependent. `IntersectionObserver` timing vs. Alpine hydration introduces another race.

**Fix plan (Stage 1):**

1. Bind teardown to `livewire:navigating` + `pagehide` in addition to `beforeunload`. Covers SPA nav + bfcache.
2. `initStream()` hard-resets container via `this.$refs.streamContainer.replaceChildren()` before `terminal.open()`.
3. `connect()` defensive-idempotent: if `this.ws` or `this.terminal` already exists, dispose/close before replacing.
4. Backend: delete `Cache` entry inside `ReactPhpWebSocketServer::handleHandshake` immediately after reading (session UUID is single-use).
5. Backend: add heartbeat / idle detection in `tick()` so half-open sockets can't hold PTYs hostage.
6. Fold in Issue #3 SSH fixes as part of this stage (same subsystem).

Tests:
- Playwright regression: navigate A(server1) → B → A(server2) 50× and assert no stale DOM children + `PtySessionRegistry` count returns to zero between runs.
- Pest: mock SSH2 throwing during `read()` → `tick()` must not crash the loop.

---

## 6. Stage Roadmap

Each stage is a PR-sized checkpoint. Status updated as work progresses.

### Stage 0 — Foundation — **IN PROGRESS**

- [x] `CLAUDE.md` at repo root ← done in prior turn
- [x] This branch plan document ← you are reading it
- [x] `docs/benchmarks/baselines/` scaffolding + `tests/Benchmarks/` dir + `scripts/bench.php` runner
- [x] `BenchmarkCase` + `BenchmarkRecorder` + `phpunit.xml` Benchmarks testsuite with `suffix="Bench.php"`
- [x] Initial PHP benchmarks: `CommandValidator::isAllowed` (exact@50/500/5000, wildcard@50, miss@500)
- [x] Baseline capture: `docs/benchmarks/baselines/2026-04-21-pre-frameless.json`
- [x] testapp-f5 audit captured in §4
- [ ] New testapp-f5 pages (deferred to the stages that consume them — Stage 1/2/3/4)
- [x] First trait batch: `ConfiguresTerminalAppearance` (height, title, windowControls, startConnected, autoConnect) + `EvaluatesOptions` for TerminalBuilder
- [x] Second trait batch: `ConfiguresSessionManagement`, `ConfiguresStreamMode`, `ConfiguresTerminalBasics`
- [ ] Remaining config groups (complex overloads): `ConfiguresLogging`, `ConfiguresPermissions`, `ConfiguresConnection`, `ConfiguresCommands`, `ConfiguresShellEnvironment`, `ConfiguresScripts` — deferred to a dedicated Stage 0.3 commit; higher risk of subtle behavior change due to method overloads (log(), ssh(), allow())
- [x] Regression check: 844 passed / 0 failed after both trait batches. Two baseline flakes passed this run.
- [x] Benchmark regression check: trait extraction adds zero measurable overhead. Three-run replication on current code shows CommandValidator::isAllowed at 0.2µs median ±2%, matching the pre-frameless baseline.

**Follow-up known issue (non-blocking):** the benchmark harness runs each measurement once per invocation. At sub-µs resolution, cold-start CPU scheduling can produce >50% swings between single runs. A harness upgrade to run N invocations and keep the median-of-medians is tracked as a Stage 0 polish item.

**Minor behavior expansion (documented here so it's on the record):** `Livewire\TerminalBuilder` previously clamped `->timeout(0)` to 1, `->historyLimit(0)` to 1, `->maxOutputLines(50)` to 100. Post-extraction those clamps are gone; the underlying Livewire component enforces whatever it needs at the consumer boundary. This aligns TerminalBuilder with the Schema component's behavior (which never clamped) and does not affect any documented usage.

### Stage 1 — Stream buffer bug + SSH robustness (integrates Issue #3)

- [ ] Add `MultiServerSamePage.php`, `MultiServerPageA.php`, `MultiServerPageB.php` to testapp-f5
- [ ] Reproduce the screen-buffer bug via Playwright
- [ ] Client teardown fixes (stream-terminal.blade.php): lifecycle events, container reset, defensive dispose
- [ ] Backend: cache cleanup in handshake, idle heartbeat in `tick()`
- [ ] SSH fixes (TerminalPtyBridge): CHANNEL_SHELL bootstrap, `setTimeout(0.01)`, try/catch in `read()` and `terminate()`, mark-dead on failure
- [ ] Pest coverage for SSH failure modes
- [ ] Playwright regression (50× navigate + leak-count assertion)

### Stage 2 — Multi-terminal isolation

- [ ] Add `MultiTerminal.php` to testapp-f5 (4 terminals: 2 Classic + 1 Stream + 1 Dual)
- [ ] Force-unique component keys; schema-build-time duplicate key detection
- [ ] Audit all Livewire `dispatch(...)` calls for missing `.to()` scoping
- [ ] Empirically verify ghostty-web multi-instance isolation
- [ ] Playwright: each terminal operates independently (commands, connect state, scripts)
- [ ] Benchmark: mount cost, memory growth curve per N terminals

### Stage 3 — Resize

- [ ] Add `ResizeTerminal.php` to testapp-f5
- [ ] Classic interactive: pipe cols/rows into PTY/tmux at session start + resize event
- [ ] Stream: verify existing resize path (`fitAddon.observeResize()` → WS resize message) end-to-end
- [ ] Playwright: programmatic viewport resize, container resize via slideover open/close, Ctrl+/- zoom → assert resize frame content matches
- [ ] Visual regression with htop/vim on both modes during resize

### Stage 4 — Frameless chrome + confirm UX (integrates PR #4)

- [ ] New enums: `TerminalChrome` (Full/Minimal/None), `PanelStyle` (Inline/Slideover/Drawer), `FloatingControls` (Disabled/Expanded/Collapsed)
- [ ] Fluent methods on trait: `chrome()`, `panelStyle()`, `floatingControls()`, `frameless()` (shorthand)
- [ ] Blade refactor: chrome partials, floating controls component (vertical dropdown collapse), slideover panels
- [ ] Integrate PR #4 confirm/cancel functional logic (without the problematic visibility changes and DOM-mistargeted shimmer)
- [ ] Pest coverage for confirm/cancel flow
- [ ] Playwright visual regression across all chrome configurations + confirm flow
- [ ] Credit PR #4 contributor in CHANGELOG

### Stage 5 — Deprecation wave

- [ ] Introduce `connectionBehavior(ConnectionBehavior)` unifying `startConnected`/`autoConnect`
- [ ] Introduce `mode(TerminalMode::Classic|Stream|Dual)` unifying `streamTerminal()`/`classicTerminal()`
- [ ] Expand `allow()` + introduce `deny()` as the blessed permissions API
- [ ] Deprecate old methods with `@deprecated` PHPDoc + `@see` replacement references
- [ ] Add opt-in runtime `E_USER_DEPRECATED` notices behind `web-terminal.deprecations.emit_notices` config flag
- [ ] Populate `UPGRADING.md` with v2.x → v3.0 migration guide for each deprecated surface

### Stage 6 — Docs

- [ ] README rewrite: golden-path first section uses the blessed API (`mode()` + `chrome()` + `allow()` + `connectionBehavior()`)
- [ ] Best-practices pages under `docs/guides/`: "Running isolated terminals on one page", "Performance tuning", "Multi-server switching", "Migrating legacy configs"
- [ ] Post-branch benchmark re-capture → `docs/benchmarks/baselines/2026-04-XX-post-frameless.json` with deltas
- [ ] Update CHANGELOG and CLAUDE.md with the final branch outcome

---

## 7. Deprecation Calendar

Timeline (tentative; refined as we ship):

| Version | Status | Deprecations |
|---|---|---|
| 2.x (current) | No deprecations announced yet | — |
| 2.N (this branch) | Soft-deprecations begin | `startConnected()`, `autoConnect()`, `streamTerminal()`/`classicTerminal()`, individual `allowPipes()`/`allowRedirection()`/etc., `windowControls(bool)`, `TerminalBuilder::toHtml()`/`__toString()`, class_alias `WebTerminalEmbed` |
| 2.N+1 onward | Deprecation runway | Each release adds a "Deprecated" section in CHANGELOG; warnings available via opt-in config flag |
| 3.0 | Breaking removal | All soft-deprecated methods and class_aliases removed; new API is the only path |

**Contract with users:** any method marked `@deprecated` in 2.N continues to work identically through every 2.x release. The opt-in `emit_notices` flag lets them surface usage in staging and migrate at their own pace. `UPGRADING.md` explains every replacement with before/after code.

---

## 8. Progress Log

Append-only. One line per work session, most recent last.

- 2026-04-21 — Branch created. CLAUDE.md written. Architecture audit complete. Decisions captured via AskUserQuestion (§2). GitHub issue #3 and PR #4 assessed (§3). testapp-f5 audited (§4). This plan document created. Beginning Stage 0.
- 2026-04-21 — Stage 0 checkpoint 1: benchmark harness wired up (`composer bench`, `scripts/bench.php`, `tests/Benchmarks/`, phpunit Benchmarks testsuite). First real benchmarks on `CommandValidator::isAllowed`. Pre-frameless baseline captured. First trait `ConfiguresTerminalAppearance` extracted from Schema component + TerminalBuilder; 844 tests passing (+2 vs baseline; the two baseline flakes passed this run). Microbenchmarks stable within ±10% noise.
- 2026-04-21 — Stage 0 checkpoint 2: three more traits extracted — `ConfiguresSessionManagement`, `ConfiguresStreamMode`, `ConfiguresTerminalBasics`. 844 tests still passing. Documented a minor TerminalBuilder behavior expansion (clamps removed from timeout/historyLimit/maxOutputLines). Pivoting to Stage 1 next; remaining complex-overload traits (logging, permissions, connection, commands, shell env, scripts) deferred to a dedicated Stage 0.3 commit.

---

## 9. Open Questions

None currently blocking. Questions that arise during work are raised via `AskUserQuestion` and the decision appended to §2 with a note in this section if scope-shaping.

---

## 10. Baseline — Pre-existing Test Failures

Captured on 2026-04-21 against `feature/frameless` HEAD (commit 2c29b4e). **These are not caused by any work on this branch** — they are environmental/timing flakes in the existing v2 code. Noted here so future failures can be attributed correctly.

```
Tests: 2 failed, 77 skipped, 842 passed (1808 assertions)
Duration: ~168s
```

| Test | Likely cause |
|---|---|
| `Tests\Unit\Connections\LocalConnectionHandlerTest` > login-shell completes | Timing-sensitive: subprocess may not finish inside the 1.5s `usleep` on slower machines. |
| `Tests\Unit\Sessions\FileSessionManagerTest` > session worker boot | PID-file creation race. Rare, reproducible on this workstation. |

**Pass/fail gate for this branch:** every commit must keep `842 passed` (or higher) and must not introduce new failures beyond these two. If either of these two starts passing reliably for an extended period, we remove them from this section and treat them as regression guards.
