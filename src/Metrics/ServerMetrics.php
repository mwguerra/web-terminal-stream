<?php

declare(strict_types=1);

namespace MWGuerra\WebTerminalStream\Metrics;

/**
 * What one WebSocket server process is doing, sampled cheaply enough to run
 * inside the event loop.
 *
 * One instance per worker. Workers share nothing — separate processes, separate
 * loops, separate caps — so each publishes its own snapshot and {@see MetricsReader}
 * adds them up. Writing per-PID files rather than cache keys keeps this working
 * on any cache driver and makes a dead worker's snapshot visibly stale instead
 * of silently merged.
 *
 * The numbers worth watching, and why:
 *
 * - `live` vs the cap: the terminal server refuses at `max_connections`, but the
 *   experience degrades well before that, so this is the scale-out trigger.
 * - `connect_ms`: SSH connect + auth runs SYNCHRONOUSLY on the loop, so while
 *   one session connects every other session on that worker stalls. Rising
 *   connect times mean rising stall time for everybody on that worker.
 * - `sweep_ms`: how long a full pass over the worker's sessions takes. It is the
 *   loop's own heartbeat — if it climbs, the worker is saturated.
 * - `refused`: capacity actually denied to a user. Never silent.
 */
final class ServerMetrics
{
    private const SAMPLE_CAP = 256;

    private int $startedAt;

    private int $opened = 0;

    private int $closed = 0;

    /** @var array<string, int> */
    private array $refused = [];

    /** @var array<string, int> live session count per connection type */
    private array $byType = [];

    /** @var list<float> */
    private array $connectMs = [];

    /** @var list<float> */
    private array $sweepMs = [];

    public function __construct(private readonly string $directory)
    {
        $this->startedAt = time();
    }

    public function sessionOpened(string $type, float $connectMs): void
    {
        $this->opened++;
        $this->byType[$type] = ($this->byType[$type] ?? 0) + 1;
        $this->push($this->connectMs, $connectMs);
    }

    public function sessionClosed(string $type): void
    {
        $this->closed++;

        if (isset($this->byType[$type])) {
            $this->byType[$type] = max(0, $this->byType[$type] - 1);
        }
    }

    public function sweepTook(float $ms): void
    {
        $this->push($this->sweepMs, $ms);
    }

    /** A user was denied capacity. The reason is the operator's next action. */
    public function refusedConnection(string $reason): void
    {
        $this->refused[$reason] = ($this->refused[$reason] ?? 0) + 1;
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(int $live): array
    {
        return [
            'pid' => getmypid(),
            'started_at' => $this->startedAt,
            'updated_at' => time(),
            'uptime_seconds' => time() - $this->startedAt,
            'live' => $live,
            'opened_total' => $this->opened,
            'closed_total' => $this->closed,
            'live_by_type' => array_filter($this->byType, static fn (int $n): bool => $n > 0),
            'refused' => $this->refused,
            'refused_total' => array_sum($this->refused),
            'connect_ms' => self::percentiles($this->connectMs),
            'sweep_ms' => self::percentiles($this->sweepMs),
        ];
    }

    /**
     * Publish this worker's snapshot. Best-effort by design: metrics must never
     * be the reason a terminal server falls over.
     */
    public function publish(int $live): void
    {
        if (! is_dir($this->directory) && ! @mkdir($this->directory, 0755, true) && ! is_dir($this->directory)) {
            return;
        }

        $path = $this->directory.'/metrics-'.getmypid().'.json';

        // Write-then-rename so a reader never sees a half-written snapshot.
        $temporary = $path.'.tmp';

        if (@file_put_contents($temporary, json_encode($this->snapshot($live), JSON_PRETTY_PRINT)) !== false) {
            @rename($temporary, $path);
        }
    }

    /** Remove this worker's snapshot on a clean shutdown. */
    public function retire(): void
    {
        @unlink($this->directory.'/metrics-'.getmypid().'.json');
    }

    /**
     * @param  list<float>  $bucket
     */
    private function push(array &$bucket, float $value): void
    {
        $bucket[] = $value;

        // Bounded: this runs for the life of the process, and an unbounded
        // sample list is a slow memory leak in a long-lived server.
        if (count($bucket) > self::SAMPLE_CAP) {
            array_shift($bucket);
        }
    }

    /**
     * @param  list<float>  $values
     * @return array<string, float|int|null>
     */
    private static function percentiles(array $values): array
    {
        if ($values === []) {
            return ['count' => 0, 'p50' => null, 'p95' => null, 'max' => null];
        }

        sort($values);
        $count = count($values);

        $at = static function (float $q) use ($values, $count): float {
            $index = (int) floor($q * ($count - 1));

            return round($values[$index], 1);
        };

        return [
            'count' => $count,
            'p50' => $at(0.5),
            'p95' => $at(0.95),
            'max' => round($values[$count - 1], 1),
        ];
    }
}
