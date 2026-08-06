# Benchmark Notes

## Interpretation guide

PHP microbenchmarks at this scale measure sub-microsecond operations.
CPU frequency scaling, opcache warm state, and OS scheduling cause
run-to-run variance that's larger than any realistic code-level
regression from a pure refactor. On the parent package's validator
benches, the observed noise floor was roughly 0.18–0.35 µs across
back-to-back runs on the same commit — expect similar for any future
sub-µs metric here.

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

Upgrade `BenchmarkCase::measure()` to run N complete sessions of K
samples each and report the minimum-of-medians, which is more stable
than a single median for noisy measurements.

Until that lands, treat `composer bench` output as advisory — useful
for spotting 10×-plus regressions, not for fine-grained perf claims.
