# Benchmarks

Advisory performance measurements for the web-terminal package. Not CI-gated — a reference for regression detection on major changes.

## Running

```bash
composer bench                                                       # run microbenchmarks, write docs/benchmarks/latest.json
php scripts/bench.php --out=docs/benchmarks/baselines/<name>.json    # capture a named baseline
php scripts/bench.php --compare=docs/benchmarks/baselines/<name>.json # run + print deltas vs baseline
```

## Files

- `tests/Benchmarks/` — Pest-style PHP microbenchmarks.
- `scripts/bench.php` — harness runner; aggregates PHP + (future) browser + (future) WS-server measurements into one JSON.
- `docs/benchmarks/baselines/` — dated JSON snapshots, one per notable milestone (e.g. pre-frameless, post-frameless, v2.N release).
- `docs/benchmarks/latest.json` — last run output (gitignored is optional; committed here when capturing an official baseline).

## What we measure

| Category | Metric | Unit |
|---|---|---|
| PHP hot paths | `CommandValidator::validate` ops/sec at 50/500/5000 whitelist entries | ops/sec |
| PHP hot paths | `Schema\Components\WebTerminal::getComponentProperties` render cost | µs/op |
| PHP hot paths | `Data\ConnectionConfig` construct + `toArray` roundtrip | µs/op |
| Classic LV | Command execution round-trip time | ms |
| Stream browser | Time-to-first-byte-rendered after connect | ms |
| Stream browser | RSS growth over 60s `htop` session | MB |
| Stream server | `terminal:serve` RSS per active PTY session | MB |
| Leak detection | `PtySessionRegistry` count before/after 50 navigations | should stay at 0 |

Browser metrics (time-to-interactive, memory, FPS) are captured via Playwright in later stages; they're not wired into the harness yet.

## Conventions

- Each measurement records **median of N runs** (default N=100 for microbench, N=10 for integration) along with **p95** and **stddev**.
- JSON keys are stable — benchmarks that exist in an old baseline must continue to report the same key name when they're kept, so historical comparison works.
- When a benchmark is removed or renamed, record the change in `CHANGELOG.md` under a "Benchmarks" section.

## Interpreting deltas

Advisory. A >10% regression on a hot-path metric warrants investigation but does not block merge. The harness prints deltas to stdout; the decision is the reviewer's.
