# Benchmark Notes

## Interpretation guide

The PHP microbenchmarks in `tests/Benchmarks/` measure sub-microsecond
operations. At that scale, CPU frequency scaling, opcache warm state,
and OS scheduling cause run-to-run variance that's larger than any
realistic code-level regression from a pure refactor.

Empirically observed run-to-run variance on the same commit:

| Measurement | Range across 3 back-to-back runs |
|---|---|
| `CommandValidator::isAllowed exact@50` | 0.181 – 0.343 µs |
| `CommandValidator::isAllowed exact@500` | 0.187 – 0.293 µs |
| `CommandValidator::isAllowed exact@5000` | 0.182 – 0.352 µs |

The 0.18–0.35 µs band is the noise floor. Deltas inside it are not
signal. A meaningful regression would be a sustained shift of a
metric's median across multiple runs and persist across a full
machine reboot cycle.

## When a delta matters

Rule of thumb — treat a delta as real when:

1. The absolute difference exceeds 2× the stddev of the slower of the
   two readings.
2. The same delta reproduces across 3 back-to-back runs on both sides
   (pre and post).
3. The delta is in a metric whose absolute value is above ~1 µs,
   where hrtime resolution and scheduling noise are proportionally
   smaller.

For sub-µs metrics, single-run comparisons are decorative — they
belong in the "is the harness running?" smoke bucket, not the
"did this PR regress perf?" bucket.

## Future harness work

Tracked in the branch plan §9 as a polish item: upgrade
`BenchmarkCase::measure()` to run N complete sessions of K samples
each and report the minimum-of-medians, which is more stable than a
single median for noisy measurements.

Until that lands, treat `composer bench` output as advisory — useful
for spotting 10×-plus regressions, not for fine-grained perf claims.
