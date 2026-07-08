# Deep Analysis — mwguerra/web-terminal-stream

Production-readiness audit and feature analysis, generated 2026-07-08 by a 12-area
multi-agent audit with adversarial verification. **Verdict: NOT production-ready** —
a critical authenticated-RCE/SSRF hole plus availability and resource-leak defects,
concentrated in the REST token endpoint, the WebSocket server, and the operational
periphery. The layout/keymap logic, the Livewire path, the fluent API, and single-node
docs are solid.

## Read in this order

1. [`00-executive-summary.md`](00-executive-summary.md) — verdict, top 5 risks, what's solid, the versioning frame correction.
2. [`roadmap.md`](roadmap.md) — prioritized P0/P1/P2/quick-wins, each with *why* and *effort*.
3. [`flows.md`](flows.md) — end-to-end terminal lifecycle map (connect/teardown/resize/split/toggle/logging).
4. [`questions.md`](questions.md) — decisions only the owner can make (start with the release/versioning state).
5. [`01-methodology.md`](01-methodology.md) — areas, agent counts, verification stats, the "0 refuted" caveat.
6. [`areas/`](areas/) — one file per audit area with the full evidence and per-flaw verdicts.

## Areas

| # | File | Verdict headline |
|---|---|---|
| 01 | [security](areas/01-security.md) | Authenticated RCE+SSRF via REST token path; plaintext-cached SSH creds; no host allow-list |
| 02 | [ws-server](areas/02-ws-server.md) | Full-server crash on one bad connection; no caps; leaks; Linux-only; no graceful shutdown |
| 03 | [cross-process](areas/03-cross-process.md) | `ws://` hardcode (mixed-content on HTTPS); shared-cache dependency unvalidated |
| 04 | [flows](areas/04-flows.md) | Lifecycle map; disconnect rows lost on SPA nav/pane close |
| 05 | [frontend](areas/05-frontend.md) | Morph-safety good; committed dist CSS stale, unguarded in CI |
| 06 | [components-api](areas/06-components-api.md) | Solid; template-inheritance + `evaluate()` parity gaps |
| 07 | [layout-engine](areas/07-layout-engine.md) | Well-built; minor `validate()`/`prefix(null)` defensive gaps |
| 08 | [logging-filament](areas/08-logging-filament.md) | Broken multi-tenancy (2 ways); half the Logs UI is dead |
| 09 | [commands-install](areas/09-commands-install.md) | Zero test coverage; `--force` duplicates migration; weak option parsing |
| 10 | [config-packaging](areas/10-config-packaging.md) | Undeclared native deps; JS asset id un-namespaced; dead config keys |
| 11 | [tests](areas/11-tests.md) | Strong pure-logic coverage; commands/server/UI untested; `composer analyse` broken |
| 12 | [docs](areas/12-docs.md) | Accurate + good single-node ops; no HA/scaling/shared-cache-trap guidance |

## Caveats

- **0 flaws were refuted** by the skeptic pass — treat the 37 unverified medium/low flaws as *leads*, not settled facts, until re-checked. The 10 confirmed critical/high flaws (and the hand-verified headline items) are trustworthy.
- The audit's "never tagged / pre-1.0" premise was **partly wrong** (9 local, unpushed tags exist). Resolve the release-state question first — it sets how urgent the P0s are.
- The intentional **no-command-whitelist** design is *not* a finding — a raw PTY can't be whitelisted; access control is the boundary.
