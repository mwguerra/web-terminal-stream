# Methodology

## Approach

A multi-agent deep audit with adversarial verification, run via the maestro `deep-analysis` workflow:

1. **Scout (inline)** — read `composer.json`, the `src/` tree, `docs/`, `git log`, `CLAUDE.md`, and repo hygiene before defining areas.
2. **Map** — 12 reader agents, one per audit area, each given concrete file anchors and area-specific questions, returning structured findings (implemented / gaps / flaws, or a flow map for the lifecycle area).
3. **Verify** — every flaw a reader tagged **critical or high** was handed to an independent skeptic agent instructed to *refute by default* — read the cited files and call chain, and only confirm if the evidence holds. This asymmetry is what filters out plausible-but-wrong findings.
4. **Synthesize** — a roadmap agent (dedupe, prioritize, ignore refuted) plus a completeness critic (what did the plan miss?), run in parallel.
5. **Hand-verification** — the lead auditor independently re-read the code behind the highest-severity claims (the REST-token RCE, the `ws://` hardcode, SSH host-key verification, the shipped private keys, the git-tag state) rather than publishing them on trust.

## Areas audited (12)

| # | Key | Focus |
|---|---|---|
| 01 | security | Token auth, Origin, gate, `#[Locked]` config derivation, SSH credential handling |
| 02 | ws-server | ReactPHP loop, concurrency, leaks, registry lifecycle, PTY bridge |
| 03 | cross-process | Token/cache correctness across HTTP app ↔ serve process, TLS URL |
| 04 | flows | End-to-end lifecycle map (connect local/SSH, teardown, resize, split, toggle, logging) |
| 05 | frontend | Alpine/Livewire morph-safety, teardown, keymap, dist assets |
| 06 | components-api | Fluent traits, prop contract, isolation, Themes |
| 07 | layout-engine | `LayoutTree` + `Keymap` purity and edge cases |
| 08 | logging-filament | Logger, events/listener, Filament resource, tenancy, retention |
| 09 | commands-install | Artisan commands, 640-LOC installer, stubs |
| 10 | config-packaging | Config keys, provider registration, deps, PHP version, isolation |
| 11 | tests | Unit/integration/e2e structure, coverage gaps, false positives, CI |
| 12 | docs | Doc accuracy vs code, production/ops completeness |

## Verification statistics

- **Agents:** 24 total (12 readers + skeptics for critical/high flaws + 2 synthesis). 23 completed; the `docs` reader (area 12) hit the structured-output retry cap and was **re-run separately** as a dedicated agent — its findings are in `areas/12-docs.md`.
- **Flaws recorded:** 47. **Re-checked by skeptics:** 10 (all critical/high). **Refuted:** 0. **Unverified (medium/low, not re-checked):** 37.
- **Gaps recorded:** 35.
- **Completeness critic:** returned 9 missed topics; the 3 highest-value (SSH host-key verification, `.gitattributes`/shipped-keys, token-route throttle) were hand-verified and folded into the roadmap.

### On "0 refuted"
Every critical/high flaw survived its skeptic. Read this with appropriate humility: it can mean the readers were accurate, or that the skeptic pass wasn't harsh enough. To hedge, the lead auditor independently re-read the code behind the top findings (see above) and each held up — so the confirmed set is trustworthy, but the 37 unverified medium/low flaws should be treated as *leads*, not settled facts, until re-checked during implementation.

## Known premise correction

The audit context told every agent the package was "never tagged, pre-1.0, breaking changes free." Direct check (`git tag`) found **9 local tags** (`v0.1.0`…`v2.3.0`), none pushed to origin, with a CHANGELOG that records no release. Findings are independent of tag status; the framing is corrected in `00-executive-summary.md` and raised as the first item in `questions.md`.

## Regenerating this report

```bash
# Re-run the full workflow (edit the script to adjust areas/prompts):
# script: .../workflows/scripts/web-terminal-stream-deep-audit-wf_4e39b8af-5e0.js
# then re-invoke the deep-analysis skill, or the Workflow tool with that scriptPath.
```

The raw per-agent results are in the workflow journal (`journal.jsonl`) under the run's transcript directory; the mechanical area/roadmap/flows/questions docs were generated from the workflow's structured return.
