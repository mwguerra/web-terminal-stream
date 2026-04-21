<?php

declare(strict_types=1);

/**
 * Benchmark harness for web-terminal.
 *
 * Runs the PHP microbenchmark suite (tests/Benchmarks/) via Pest, aggregates
 * samples recorded through BenchmarkRecorder, and emits a JSON report.
 *
 * Usage:
 *   php scripts/bench.php                           # runs + writes docs/benchmarks/latest.json
 *   php scripts/bench.php --out=path/to/file.json   # custom output path
 *   php scripts/bench.php --compare=baseline.json   # compare against a baseline after running
 *
 * Advisory only. Never gates CI.
 */

$args = [];
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--')) {
        $eq = strpos($arg, '=');
        if ($eq === false) {
            $args[substr($arg, 2)] = true;
        } else {
            $args[substr($arg, 2, $eq - 2)] = substr($arg, $eq + 1);
        }
    }
}

$root = dirname(__DIR__);
$outPath = $args['out'] ?? $root . '/docs/benchmarks/latest.json';

// Run Pest on the Benchmarks directory — BenchmarkRecorder collects samples.
// We run in a subprocess and capture the serialized recorder export via a temp file.
$recorderDump = tempnam(sys_get_temp_dir(), 'wt-bench-');

putenv("WEB_TERMINAL_BENCH_DUMP={$recorderDump}");

$pestCmd = sprintf(
    '%s %s --colors=never 2>&1',
    escapeshellarg(PHP_BINARY),
    escapeshellarg($root . '/vendor/bin/pest'),
);

$pestCmd .= ' --testsuite=Benchmarks';

echo "→ Running PHP microbenchmarks...\n";
passthru($pestCmd, $exitCode);

if (! file_exists($recorderDump) || filesize($recorderDump) === 0) {
    fwrite(STDERR, "\nNo benchmark samples recorded. Did the suite run any measure() calls?\n");
    exit(1);
}

$samples = json_decode((string) file_get_contents($recorderDump), true);
@unlink($recorderDump);

$report = [
    'captured_at' => date('c'),
    'commit' => trim((string) shell_exec('git -C ' . escapeshellarg($root) . ' rev-parse --short HEAD')),
    'branch' => trim((string) shell_exec('git -C ' . escapeshellarg($root) . ' branch --show-current')),
    'php' => PHP_VERSION,
    'uname' => php_uname('s') . ' ' . php_uname('r'),
    'measurements' => $samples,
];

@mkdir(dirname($outPath), 0755, true);
file_put_contents($outPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

echo "\n✓ Report written to {$outPath}\n";

if (isset($args['compare'])) {
    $baseline = json_decode((string) file_get_contents($args['compare']), true);
    if (! isset($baseline['measurements'])) {
        fwrite(STDERR, "Baseline file missing 'measurements' key.\n");
        exit(1);
    }

    echo "\n→ Deltas vs " . $args['compare'] . "\n\n";
    printf("%-60s  %10s  %10s  %8s\n", 'measurement', 'baseline µs', 'now µs', 'Δ%');
    echo str_repeat('-', 95) . "\n";

    foreach ($samples as $name => $current) {
        if (! isset($baseline['measurements'][$name])) {
            printf("%-60s  %10s  %10.1f  %8s\n", $name, '(new)', $current['median_us'], '—');
            continue;
        }
        $base = $baseline['measurements'][$name]['median_us'];
        $now = $current['median_us'];
        $delta = $base > 0 ? (($now - $base) / $base) * 100 : 0;
        $marker = abs($delta) < 5 ? ' ' : ($delta > 0 ? '↑' : '↓');
        printf("%-60s  %10.1f  %10.1f  %7.1f%% %s\n", $name, $base, $now, $delta, $marker);
    }
    echo "\n";
}

exit($exitCode);
