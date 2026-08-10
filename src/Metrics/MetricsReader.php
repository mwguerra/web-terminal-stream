<?php

declare(strict_types=1);

namespace MWGuerra\WebTerminalStream\Metrics;

/**
 * Reads what the WebSocket workers published, from the web side.
 *
 * The app and the terminal server are separate processes — often separate
 * supervisors — so this never talks to a server directly. It reads the per-PID
 * snapshots {@see ServerMetrics} writes and adds them up.
 *
 * A worker that dies mid-flight leaves its last snapshot behind. Those are
 * reported as STALE rather than merged: a fleet that silently keeps counting a
 * dead worker's sessions is worse than one that admits a worker is missing.
 */
final class MetricsReader
{
    public function __construct(
        private readonly string $directory,
        /** A snapshot older than this is treated as a dead worker. */
        private readonly int $staleAfterSeconds = 30,
    ) {}

    public static function make(): self
    {
        return new self(
            storage_path('web-terminal-stream'),
            (int) config('web-terminal-stream.metrics.stale_after_seconds', 30),
        );
    }

    /**
     * @return array{
     *     available: bool,
     *     workers: int,
     *     stale_workers: int,
     *     live: int,
     *     capacity: int,
     *     saturation: float|null,
     *     refused_total: int,
     *     connect_ms: array<string, float|int|null>,
     *     sweep_ms: array<string, float|int|null>,
     *     live_by_type: array<string, int>,
     *     oldest_uptime_seconds: int|null,
     *     per_worker: list<array<string, mixed>>,
     * }
     */
    public function read(): array
    {
        $snapshots = $this->snapshots();
        $now = time();

        $fresh = [];
        $stale = 0;

        foreach ($snapshots as $snapshot) {
            if ($now - (int) ($snapshot['updated_at'] ?? 0) > $this->staleAfterSeconds) {
                $stale++;

                continue;
            }

            $fresh[] = $snapshot;
        }

        $perWorkerCap = (int) config('web-terminal-stream.stream.max_connections', 100);
        // Caps are enforced per worker because workers share nothing, so fleet
        // capacity is the per-worker ceiling times the workers actually alive —
        // not the configured worker count, which may include one that died.
        $capacity = $perWorkerCap > 0 ? $perWorkerCap * count($fresh) : 0;
        $live = (int) array_sum(array_column($fresh, 'live'));

        return [
            'available' => $fresh !== [],
            'workers' => count($fresh),
            'stale_workers' => $stale,
            'live' => $live,
            'capacity' => $capacity,
            'saturation' => $capacity > 0 ? round($live / $capacity, 3) : null,
            'refused_total' => (int) array_sum(array_column($fresh, 'refused_total')),
            'connect_ms' => self::worstOf($fresh, 'connect_ms'),
            'sweep_ms' => self::worstOf($fresh, 'sweep_ms'),
            'live_by_type' => self::sumByType($fresh),
            'oldest_uptime_seconds' => $fresh === [] ? null : max(array_column($fresh, 'uptime_seconds')),
            'per_worker' => $fresh,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function snapshots(): array
    {
        $files = glob($this->directory.'/metrics-*.json') ?: [];
        $out = [];

        foreach ($files as $file) {
            $raw = @file_get_contents($file);

            if ($raw === false || $raw === '') {
                continue;
            }

            $decoded = json_decode($raw, true);

            if (is_array($decoded)) {
                $out[] = $decoded;
            }
        }

        return $out;
    }

    /**
     * The WORST worker's latency, not the average.
     *
     * A fleet is only as good as its slowest loop: averaging hides the one
     * worker that is stalling its users, which is exactly the case an operator
     * needs to see.
     *
     * @param  list<array<string, mixed>>  $snapshots
     * @return array<string, float|int|null>
     */
    private static function worstOf(array $snapshots, string $key): array
    {
        $worst = ['count' => 0, 'p50' => null, 'p95' => null, 'max' => null];

        foreach ($snapshots as $snapshot) {
            $candidate = $snapshot[$key] ?? null;

            if (! is_array($candidate) || ($candidate['count'] ?? 0) === 0) {
                continue;
            }

            if ($worst['p95'] === null || (float) ($candidate['p95'] ?? 0) > (float) $worst['p95']) {
                $worst = $candidate;
            }
        }

        return $worst;
    }

    /**
     * @param  list<array<string, mixed>>  $snapshots
     * @return array<string, int>
     */
    private static function sumByType(array $snapshots): array
    {
        $totals = [];

        foreach ($snapshots as $snapshot) {
            foreach ((array) ($snapshot['live_by_type'] ?? []) as $type => $count) {
                $totals[(string) $type] = ($totals[(string) $type] ?? 0) + (int) $count;
            }
        }

        return $totals;
    }
}
