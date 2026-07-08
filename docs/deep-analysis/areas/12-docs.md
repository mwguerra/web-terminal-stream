# 12 — Documentation accuracy, completeness, production guidance

**Area key:** `docs` — *(reader re-run after the original agent hit the structured-output retry cap; findings below independently spot-checked by the lead auditor)*

## Summary

The documentation is unusually mature and internally consistent for a pre-release package. `README.md`, `docs/architecture.md`, `CLAUDE.md`, and `UPGRADING.md` are current with the code: every removed API cited in the audit brief appears *only* in the UPGRADING migration tables (their intended home) or in banner-marked historical docs — the **live docs carry no stale API names**. The newest features (TerminalDashboard, Themes, layout presets) are documented. The gaps are not correctness errors but **omissions in production/ops guidance** — chiefly that the *shared, persistent cache* requirement between the HTTP app and the `terminal-stream:serve` process is only alluded to and never states the concrete footgun: a default `array`/per-request cache silently breaks the whole terminal (`Cache::pull` in the serve process always misses).

Bottom line on the headline question: **a competent DevOps engineer can deploy a single-node install from the docs alone** — supervisor/systemd units, an nginx wss reverse-proxy, direct-TLS, the origin allow-list, and the `useStreamTerminal` gate are all documented and correct. But they will likely hit the silent array-cache trap, and they cannot design a resilient / multi-node / HA / capacity-planned deployment from these docs because those topics do not exist. For a package that hands out real shell access, that ops gap is the material documentation risk.

## What exists and works

- Fluent API reference table (`README.md:352-370`) matches the traits in `src/Concerns/*`.
- `connectionBehavior` Manual/Auto/Always documented across README, architecture §1, UPGRADING §4b — matches `src/Enums/ConnectionBehavior.php`.
- TerminalDashboard (`README.md:508-537`), Themes (`README.md:539-601`), layout presets (`README.md:534`) all match the code.
- Single-node ops IS documented: supervisor + systemd (`README.md:117-143`), wss reverse-proxy + direct-TLS (`README.md:145-171`), origin allow-list (`README.md:174-191`), env-var table (`README.md:195-212`).
- Security model + no-whitelist rationale consistent across README warning block, architecture §9.
- `docs/design/2026-03-30-*` and `docs/plans/2026-04-21-frameless-branch-plan.md` open with a "Historical document" banner.

## Gaps (missing docs)

| Gap | Severity | Detail |
|---|:--:|---|
| Shared persistent-cache requirement never stated concretely | **critical** | The HTTP app and serve process communicate connection config through the Laravel cache; docs only say "a different cache store breaks both" (`README.md:738`). Never states that `CACHE_STORE=array` or any per-process store makes `Cache::pull` always miss → terminal silently never connects. No redis/memcached recommendation; no note that multi-app-server deployments need a shared store. Highest-impact production trap. |
| No scaling / HA guidance | high | `terminal-stream:serve` is a single long-running process = SPOF. Docs never cover: no horizontal LB without shared cache + sticky WS routing; restart drops every live PTY; one instance per node. `grep scaling\|high availab\|load balanc\|sticky` over README/docs → nothing. |
| No connection/resource limits guidance | high | No global cap on concurrent WS connections or per-user sessions documented; no sizing (memory/CPU per PTY), no `ulimit`/FD tuning for many PTYs. |
| No OS-level log rotation | medium | Supervisor `stdout_logfile` shown but no logrotate/`copytruncate`; the serve log grows unbounded. (DB retention via `logs:cleanup` is well covered.) |
| No health-check / graceful-restart / zero-downtime guidance | medium | No liveness probe for serve; no warning that a deploy restarting serve kills all active shells. |
| Undocumented config keys | medium | `logging.truncate_output`, `logging.user_table`, `logging.user_foreign_key`, `workspace.shortcuts.prefix_timeout` not in the README. |
| Directional splits undocumented in README | medium | `PaneAction::SplitLeft/Right/Up/Down` exist and are in the CHANGELOG, but the README keymap section (`README.md:479-490`) shows only `SplitVertical`. |
| Caddy named but not shown; firewall one-liner | low | `README.md:149` says "nginx/Caddy" but only nginx is shown; `README.md:37` "firewall its port" with no ufw/iptables example. |

## Flaws (documented-but-wrong)

### Versioning state is internally contradictory (independently confirmed)
- **Severity:** medium — **Verdict:** ✅ confirmed (lead auditor: `git tag` shows 9 local tags v0.1.0–v2.3.0; `git ls-remote --tags origin` is EMPTY; `CHANGELOG.md:8` is entirely `## [Unreleased]`; `README.md:11` compat table says "1.x")
- **Detail:** Four sources disagree about what version this is: 9 **local-only** tags reach v2.3.0, none are pushed to origin, the CHANGELOG records **zero** releases, and the README advertises 1.x. This also refutes the audit's working premise of "never tagged." Practical read: no remote tags + no CHANGELOG releases ⇒ effectively unreleased to Composer consumers, but the local-tag mess must be reconciled with the owner (are those tags intentional? is it on Packagist?). See `questions.md`.

### PHP version floor stated three ways; 8.5 unverified
- **Severity:** medium — **Verdict:** ✅ confirmed
- **Detail:** `composer.json:23` requires `php: ^8.2`; `CLAUDE.md:14` and `README.md:11` say "PHP 8.3+"; CI runs only 8.3/8.4; the dev machine runs 8.5.7 (allowed by `^8.2`, tested nowhere). Pick a real floor, make the constraint match the docs, add 8.5 to CI.

### `docs/plans/2026-07-01-post-extraction-roadmap.md` is stale but unmarked
- **Severity:** low — **Verdict:** ✅ confirmed
- **Detail:** Labelled `Status: approved, in execution` and describes already-shipped items (WS deps in `require`, origin validation, `connectionBehavior`) as not-yet-done, and still names removed enum cases `AutoHidden`/`AutoWithButton` (now `Manual/Auto/Always`) and `TerminalGrid::terminals()` (now `panes()`). Unlike the other historical docs it has no banner. Mark it completed/historical.

### `docs/benchmarks/` describes capability that isn't wired
- **Severity:** low (info) — **Verdict:** ⚪ noted
- **Detail:** `docs/benchmarks/README.md` honestly says "no registered benchmarks," but `composer bench` and the "Candidate metrics" table read as available. Add a one-line "not yet wired" note at the top.
