<?php

declare(strict_types=1);

/**
 * Merge per-profile stress summaries (written by ConnectionStressBench via
 * WTS_STRESS_OUT) into one report file and print a comparison table.
 *
 * Usage: php scripts/stress/report.php <summaries-dir> <out.json>
 */
[$script, $inDir, $outPath] = $argv + [null, null, null];

if (! is_dir((string) $inDir)) {
    fwrite(STDERR, "No summaries directory: {$inDir}\n");
    exit(1);
}

$profiles = [];
foreach (glob($inDir.'/*.json') ?: [] as $file) {
    $summary = json_decode((string) file_get_contents($file), true);
    if (is_array($summary) && isset($summary['profile'])) {
        $profiles[$summary['profile']] = $summary;
    }
}

if ($profiles === []) {
    fwrite(STDERR, "No profile summaries found in {$inDir}\n");
    exit(1);
}

$root = dirname(__DIR__, 2);
$report = [
    'captured_at' => date('c'),
    'commit' => trim((string) shell_exec('git -C '.escapeshellarg($root).' rev-parse --short HEAD')),
    'php' => PHP_VERSION,
    'profiles' => $profiles,
];

@mkdir(dirname((string) $outPath), 0755, true);
file_put_contents((string) $outPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

echo "\n✓ Report written to {$outPath}\n\n";

printf(
    "%-14s %12s %14s %12s %12s %12s %12s %14s\n",
    'profile', 'established', 'connect p95', 'ttfb p95', 'rtt p50', 'rtt p95', 'rtt p99', 'overcap rej.',
);
echo str_repeat('-', 106)."\n";

foreach ($profiles as $name => $s) {
    printf(
        "%-14s %12s %14s %12s %12s %12s %12s %14s\n",
        $name,
        $s['established'].'/'.$s['fleet'],
        isset($s['connect_ms']['p95']) ? $s['connect_ms']['p95'].'ms' : '—',
        isset($s['ttfb_ms']['p95']) ? $s['ttfb_ms']['p95'].'ms' : '—',
        isset($s['rtt_ms']['p50']) ? $s['rtt_ms']['p50'].'ms' : '—',
        isset($s['rtt_ms']['p95']) ? $s['rtt_ms']['p95'].'ms' : '—',
        isset($s['rtt_ms']['p99']) ? $s['rtt_ms']['p99'].'ms' : '—',
        $s['overcap_rejected'].'/'.$s['overcap_attempted'],
    );
}
echo "\n";
