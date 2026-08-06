# Benchmarks

Advisory performance measurements for the web-terminal-stream package. Not CI-gated — a reference for regression detection on major changes.

## Running

```bash
composer bench                                                        # run microbenchmarks, write docs/benchmarks/latest.json
php scripts/bench.php --out=docs/benchmarks/baselines/<name>.json     # capture a named baseline
php scripts/bench.php --compare=docs/benchmarks/baselines/<name>.json # run + print deltas vs baseline
composer stress                                                       # connection stress matrix, write docs/benchmarks/stress-latest.json
```

## Connection stress harness (`composer stress`)

Drives N concurrent token-authenticated WebSocket→SSH-PTY sessions against a
real `terminal-stream:serve` process, swept across latency profiles, and
verifies the server's behavior under load (see `scripts/stress/run.sh` and
`tests/Benchmarks/ConnectionStressBench.php`).

Per profile it opens **100 connections** (10ms launch ramp; overridable via
`WTS_STRESS_CONNECTIONS`), each holding a live PTY for a deterministic
**3–10s lifetime** while running an echo ping-pong whose marker is expanded
remotely (`$((…))`) — a match proves a full PTY round-trip. Once the fleet is
established, an **over-cap probe** opens 10 more connections that the server
must refuse at `stream.max_connections` (HTTP 503) without disturbing the
fleet. After the run, the `PtySessionRegistry` must drain back to its
baseline (leak check).

Latency profiles (default matrix: `baseline client-10ms client-500ms
ssh-10ms ssh-500ms`, override via `WTS_STRESS_PROFILES`):

- `client-<N>ms` — [Toxiproxy](https://github.com/Shopify/toxiproxy) between
  the WS client and the server: browser-side network lag.
- `ssh-<N>ms` — `tc netem` on the sshd container egress (needs the
  `NET_ADMIN` cap wired in `tests/docker/compose.yaml`): a distant SSH host.
- Values are one-way delay per direction (netem semantics): a 500ms profile
  adds ~1s to every round-trip.

**Measured architectural limit:** the server performs each session's SSH
connect + auth *synchronously on its event loop*, so establishment is serial
and RTT-bound — ~6.5s per session at 500ms one-way delay, during which every
already-connected session's I/O stalls. Slow-SSH profiles (≥100ms) therefore
run a bounded fleet (default 25, `WTS_STRESS_SLOW_SSH_CONNECTIONS` to
override) with a wider run deadline; the per-profile `fleet` value is always
recorded in the report, so nothing is capped silently.

Reported per profile: handshake success rate, connect/TTFB/RTT p50-p95-p99,
over-cap rejections, and error samples — merged into
`docs/benchmarks/stress-latest.json` with a comparison table on stdout.

Notes: the stress server binds `0.0.0.0` for the seconds each profile runs
(toxiproxy must reach it via `host.docker.internal`; every handshake still
requires a valid single-use token). The sshd container raises `MaxStartups`
so OpenSSH itself doesn't throttle the herd. Everything is opt-in behind
`WTS_STRESS=1` — plain `composer bench` and CI never run it.

## Files

- `tests/Benchmarks/` — Pest-style PHP microbenchmarks (`*Bench.php` files, run via the `Benchmarks` PHPUnit test suite). `BenchmarkCase` and `BenchmarkRecorder` are the shared harness.
- `scripts/bench.php` — harness runner; aggregates measurements into one JSON report.
- `docs/benchmarks/baselines/` — dated JSON snapshots, one per notable milestone.

## Current state

One benchmark is registered: the **connection stress harness** (`tests/Benchmarks/ConnectionStressBench.php`, run via `composer stress` — see above; it self-skips under plain `composer bench`). No PHP *microbenchmarks* are registered: the only one inherited from `mwguerra/web-terminal` measured `CommandValidator`, which does not exist in this stream-only package (a raw PTY has no command whitelist). The parent package's baselines were deleted with it — their measurement keys have no counterpart here.

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
