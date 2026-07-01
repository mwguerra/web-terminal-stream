# Benchmarks

Advisory performance measurements for the web-terminal-stream package. Not CI-gated — a reference for regression detection on major changes.

## Running

```bash
composer bench                                                        # run microbenchmarks, write docs/benchmarks/latest.json
php scripts/bench.php --out=docs/benchmarks/baselines/<name>.json     # capture a named baseline
php scripts/bench.php --compare=docs/benchmarks/baselines/<name>.json # run + print deltas vs baseline
```

## Files

- `tests/Benchmarks/` — Pest-style PHP microbenchmarks (`*Bench.php` files, run via the `Benchmarks` PHPUnit test suite). `BenchmarkCase` and `BenchmarkRecorder` are the shared harness.
- `scripts/bench.php` — harness runner; aggregates measurements into one JSON report.
- `docs/benchmarks/baselines/` — dated JSON snapshots, one per notable milestone.

## Current state

The package currently ships the harness but **no registered benchmarks**: the only PHP microbenchmark inherited from `mwguerra/web-terminal` measured `CommandValidator`, which does not exist in this stream-only package (a raw PTY has no command whitelist). The parent package's baselines were deleted with it — their measurement keys have no counterpart here.

Candidate metrics for this package (not wired yet):

| Category | Metric | Unit |
|---|---|---|
| PHP hot paths | `Schemas\Components\WebTerminalStream::getComponentProperties` render cost | µs/op |
| PHP hot paths | `Data\ConnectionConfig` construct + `toArray` roundtrip | µs/op |
| Stream browser | Time-to-first-byte-rendered after connect | ms |
| Stream browser | RSS growth over 60s `htop` session | MB |
| Stream server | `terminal-stream:serve` RSS per active PTY session | MB |
| Leak detection | `PtySessionRegistry` count before/after 50 navigations | should stay at 0 |

Browser/server metrics would be captured via Playwright and are not wired into the harness.

## Conventions

- Each measurement records **median of N runs** (default N=100 for microbench, N=10 for integration) along with **p95** and **stddev**.
- JSON keys are stable — benchmarks that exist in an old baseline must continue to report the same key name when they're kept, so historical comparison works.
- When a benchmark is removed or renamed, record the change in `CHANGELOG.md`.

## Interpreting deltas

Advisory. A >10% regression on a hot-path metric warrants investigation but does not block merge. The harness prints deltas to stdout; the decision is the reviewer's. See `NOTES.md` for the noise-floor guidance.
