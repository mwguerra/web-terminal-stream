# Executive Summary — Production-Readiness Audit

**Package:** `mwguerra/web-terminal-stream` — a Stream-mode PTY-over-WebSocket web terminal for Laravel 12–13 + Filament 5 + Livewire 4.
**Date:** 2026-07-08 · **Method:** 12-area multi-agent audit with adversarial (refute-by-default) verification; headline findings re-verified by hand.

## Verdict: NOT production-ready

This package hands the browser a **real interactive shell / SSH session**, so the bar is high — and the audit found a **critical authorization hole plus a cluster of availability and resource-leak defects** that make a public release irresponsible today. The good news: the problems are *concentrated*. The pure layout/keymap logic, the Livewire security path, the fluent-config layer, and the docs' single-node ops guidance are genuinely well-built and well-tested. The danger is concentrated in three places: the **REST token endpoint**, the **long-running WebSocket server**, and the **operational/packaging periphery** (multi-tenant logging, deps, distribution hygiene).

### The one that matters most
`Http/Controllers/TerminalWebSocketController::generateToken` (line 18) reads the **entire `connectionConfig` from client input** and mints a valid single-use WebSocket token **with no `useStreamTerminal` gate check**. The route is registered with only `['web','auth']`. So **any authenticated user** — not just a privileged operator — can request a token for `{type:'local'}` (a shell on the app host) or `{type:'ssh', host:…, username:…, password:…}` (an SSH pivot to any reachable host). This is effectively **authenticated RCE + SSRF**, and it bypasses every invariant the Livewire path carefully enforces (that path *does* gate at `StreamTerminal.php:100` and derives config from `#[Locked]` server state). Verified by direct read of both paths.

## Headline numbers
- **12 areas** audited by 12 reader agents; **10 critical/high flaws** independently re-checked by skeptic agents, **0 refuted**, **37** medium/low flaws recorded but not re-checked; **35 gaps**; a completeness-critic pass surfaced 3 more verified issues the readers missed.
- Roadmap: **6 P0 · 14 P1 · 12 P2 · 6 quick wins** (plus the 3 critic additions).
- ~7,660 LOC PHP `src/` + ~1,500 LOC blade/JS; ~4,900 LOC tests (unit + Docker integration + Playwright e2e).

## Top 5 risks (fix before any real deployment)
1. **Authenticated RCE + SSRF via the REST token endpoint** — no gate, client-supplied connection config (`security`, P0).
2. **Trivial full-server DoS** — one wrong SSH password or a `proc_open` failure throws out of the ReactPHP data callback and kills the whole process for *every* live session (`ws-server`, P0). No `try/catch` around `bridge->start()`.
3. **Unbounded shell-fork DoS + FD/PID/memory leaks** — no per-user/global session cap, no throttle on token minting, and dead shells (`exit`, dropped SSH) are never reaped (`ws-server`, P0/P1).
4. **Undeclared, unchecked native deps** — the server needs `ext-posix`/`ext-pcntl`/Unix `proc_open` PTYs; none are in `composer.json` or preflight-checked, so it installs cleanly and then fatals at runtime (and is **Linux-only** — silently dies on macOS/BSD, the dev platform) (`config-packaging`/`ws-server`, P0/P1).
5. **Multi-tenancy is broken two ways** — the `--with-tenant` migration + default config make *every* log insert violate a NOT-NULL FK and get silently swallowed (dead audit trail), and the Filament Logs resource has **zero tenant scoping** (cross-tenant exposure of IPs, SSH hosts/usernames) (`logging-filament`, P0).

Two more, surfaced by the completeness critic and hand-verified: **no SSH host-key verification** (every SSH terminal is MITM-able — `phpseclib3` doesn't verify by default), and **no `.gitattributes`**, so the published Composer tarball ships real committed **private SSH test keys** (`tests/docker/keys/wts_test_key`) plus the whole `tests/`/`docs/`/`scripts/` tree.

## What is genuinely solid
- **`Data/Layout/LayoutTree` + `Data/Keymap`** — pure, well-factored, exhaustively unit-tested; the recent PHP 8.5 spread-destructuring fatal was already fixed.
- **The Livewire connection path** (`StreamTerminal`, `StreamWorkspace`, `StreamDashboard`) — gates correctly, keeps connection config in `#[Locked]` server state, derives new panes/sources server-side; the frontend morph-safety and teardown design is careful and documented.
- **The fluent component API + shared traits** — consistent, DX-friendly, heavily tested (375 unit tests).
- **Single-node deployment docs** — supervisor/systemd, nginx `wss` reverse-proxy, direct-TLS, origin allow-list are all present and correct.
- **i18n parity** (en / pt_BR key-for-key) and the side-by-side isolation model (one JS-asset-id slip aside).

## Frame correction (important)
The audit ran under the stated premise "never tagged, pre-1.0, breaking changes free." That is **partly false**: 9 **local** tags exist (`v0.1.0`…`v2.3.0`), though **none are pushed to origin** and the CHANGELOG records no release. The technical findings are independent of tag status, but **the first thing to confirm with the owner is the real release/exposure state** (see `questions.md`) — it decides whether the P0s are latent or actively exploitable, and whether breaking fixes need a deprecation path.

## Recommendation
Keep it unreleased. Clear the P0 authorization + availability cluster, declare/guard the native deps, fix the multi-tenant logging, add host-key verification + `.gitattributes`, and add command/installer test coverage. Then reconcile the versioning mess and consider a real `v0.x` tag. See `roadmap.md` for the sequenced plan.
