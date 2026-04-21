<?php

declare(strict_types=1);

namespace MWGuerra\WebTerminal\Tests\Benchmarks;

use MWGuerra\WebTerminal\Tests\TestCase;

/**
 * Base class for benchmark suites.
 *
 * Benchmarks extend this to get a Testbench environment (config, container)
 * while keeping measurements isolated from the functional Pest suite.
 *
 * Results are accumulated via BenchmarkRecorder and written to a JSON file
 * by scripts/bench.php.
 */
abstract class BenchmarkCase extends TestCase
{
    protected function measure(string $name, callable $op, int $runs = 100): void
    {
        $samples = [];

        for ($i = 0; $i < $runs; $i++) {
            $start = hrtime(true);
            $op();
            $samples[] = (hrtime(true) - $start) / 1_000;
        }

        BenchmarkRecorder::record($name, $samples);
    }
}
