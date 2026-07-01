<?php

declare(strict_types=1);

namespace MWGuerra\WebTerminalStream\Tests\Benchmarks;

/**
 * In-memory accumulator for benchmark samples.
 *
 * Each `record($name, $samples)` stores raw microsecond samples for the
 * named measurement. `export()` produces the canonical JSON shape consumed
 * by scripts/bench.php (median, p95, stddev, min, max, runs).
 */
final class BenchmarkRecorder
{
    /** @var array<string, array<int, float>> */
    private static array $samples = [];

    private static bool $shutdownRegistered = false;

    /**
     * @param  array<int, float>  $samples  Microsecond samples.
     */
    public static function record(string $name, array $samples): void
    {
        self::$samples[$name] = array_merge(self::$samples[$name] ?? [], $samples);

        if (! self::$shutdownRegistered && getenv('WEB_TERMINAL_BENCH_DUMP') !== false) {
            register_shutdown_function(fn () => self::flush((string) getenv('WEB_TERMINAL_BENCH_DUMP')));
            self::$shutdownRegistered = true;
        }
    }

    public static function flush(string $path): void
    {
        if (empty(self::$samples)) {
            return;
        }

        file_put_contents($path, json_encode(self::export(), JSON_PRETTY_PRINT));
    }

    public static function reset(): void
    {
        self::$samples = [];
    }

    /**
     * @return array<string, array{runs: int, median_us: float, p95_us: float, stddev_us: float, min_us: float, max_us: float}>
     */
    public static function export(): array
    {
        $out = [];
        foreach (self::$samples as $name => $samples) {
            sort($samples);
            $n = count($samples);
            $mean = array_sum($samples) / $n;
            $variance = array_sum(array_map(fn ($s) => ($s - $mean) ** 2, $samples)) / $n;

            $out[$name] = [
                'runs' => $n,
                'median_us' => round($samples[(int) floor($n / 2)], 3),
                'p95_us' => round($samples[(int) floor($n * 0.95)], 3),
                'stddev_us' => round(sqrt($variance), 3),
                'min_us' => round($samples[0], 3),
                'max_us' => round($samples[$n - 1], 3),
            ];
        }

        return $out;
    }
}
