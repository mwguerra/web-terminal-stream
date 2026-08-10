<?php

declare(strict_types=1);

use MWGuerra\WebTerminalStream\Metrics\MetricsReader;
use MWGuerra\WebTerminalStream\Metrics\ServerMetrics;

/*
 * Fleet metrics.
 *
 * The app and the WebSocket workers are separate processes, so the widgets
 * cannot ask a server anything — each worker publishes a snapshot and the
 * reader adds them up. Everything here exists to keep that aggregation honest
 * under the two states that actually mislead an operator: a dead worker, and
 * one slow worker hidden behind healthy siblings.
 */

function metricsDir(): string
{
    $dir = sys_get_temp_dir().'/wts-metrics-'.uniqid();
    mkdir($dir, 0755, true);

    return $dir;
}

function writeSnapshot(string $dir, int $pid, array $overrides = []): void
{
    file_put_contents($dir."/metrics-{$pid}.json", json_encode(array_merge([
        'pid' => $pid,
        'started_at' => time() - 600,
        'updated_at' => time(),
        'uptime_seconds' => 600,
        'live' => 5,
        'opened_total' => 10,
        'closed_total' => 5,
        'live_by_type' => ['ssh' => 5],
        'refused' => [],
        'refused_total' => 0,
        'connect_ms' => ['count' => 10, 'p50' => 100.0, 'p95' => 200.0, 'max' => 250.0],
        'sweep_ms' => ['count' => 10, 'p50' => 1.0, 'p95' => 2.0, 'max' => 3.0],
    ], $overrides)));
}

describe('ServerMetrics', function () {
    it('publishes a snapshot a reader can pick up, and withdraws it on retire', function () {
        $dir = metricsDir();
        $metrics = new ServerMetrics($dir);

        $metrics->publish(3);
        expect(glob($dir.'/metrics-*.json'))->toHaveCount(1);

        // A planned shutdown must not look like a crashed worker.
        $metrics->retire();
        expect(glob($dir.'/metrics-*.json'))->toBeEmpty();
    });

    it('reports percentiles over recorded samples', function () {
        $metrics = new ServerMetrics(metricsDir());

        foreach ([10, 20, 30, 40, 50, 60, 70, 80, 90, 100] as $ms) {
            $metrics->sessionOpened('ssh', (float) $ms);
        }

        $snapshot = $metrics->snapshot(live: 10);

        expect($snapshot['connect_ms']['count'])->toBe(10)
            ->and($snapshot['connect_ms']['max'])->toBe(100.0)
            ->and($snapshot['connect_ms']['p50'])->toBeGreaterThanOrEqual(50.0);
    });

    it('says nothing rather than zero when it has no samples', function () {
        // A latency of "0ms" would read as excellent; the truth is "unmeasured".
        $snapshot = (new ServerMetrics(metricsDir()))->snapshot(live: 0);

        expect($snapshot['connect_ms']['p95'])->toBeNull()
            ->and($snapshot['connect_ms']['count'])->toBe(0);
    });

    it('bounds its sample buffers so a long-lived server does not grow', function () {
        $metrics = new ServerMetrics(metricsDir());

        for ($i = 0; $i < 1000; $i++) {
            $metrics->sweepTook(1.0);
        }

        expect($metrics->snapshot(0)['sweep_ms']['count'])->toBeLessThanOrEqual(256);
    });

    it('counts refusals by reason — the operator\'s next action', function () {
        $metrics = new ServerMetrics(metricsDir());
        $metrics->refusedConnection('server at capacity (100 connections)');
        $metrics->refusedConnection('server at capacity (100 connections)');
        $metrics->refusedConnection('user session limit');

        $snapshot = $metrics->snapshot(0);

        expect($snapshot['refused_total'])->toBe(3)
            ->and($snapshot['refused']['user session limit'])->toBe(1);
    });

    it('tracks live sessions per connection type across open and close', function () {
        $metrics = new ServerMetrics(metricsDir());
        $metrics->sessionOpened('ssh', 10.0);
        $metrics->sessionOpened('ssh', 10.0);
        $metrics->sessionOpened('local', 1.0);
        $metrics->sessionClosed('ssh');

        expect($metrics->snapshot(2)['live_by_type'])->toBe(['ssh' => 1, 'local' => 1]);
    });
});

describe('MetricsReader', function () {
    it('reports unavailable when no worker has published', function () {
        $reader = new MetricsReader(metricsDir());

        expect($reader->read()['available'])->toBeFalse()
            ->and($reader->read()['workers'])->toBe(0);
    });

    it('adds up live sessions across workers', function () {
        $dir = metricsDir();
        writeSnapshot($dir, 1, ['live' => 4]);
        writeSnapshot($dir, 2, ['live' => 6]);

        $result = (new MetricsReader($dir))->read();

        expect($result['workers'])->toBe(2)
            ->and($result['live'])->toBe(10);
    });

    it('counts a worker that stopped publishing as missing, not as healthy', function () {
        $dir = metricsDir();
        writeSnapshot($dir, 1);
        writeSnapshot($dir, 2, ['updated_at' => time() - 3600, 'live' => 99]);

        $result = (new MetricsReader($dir, staleAfterSeconds: 30))->read();

        // The dead worker's 99 sessions must NOT be counted — a fleet that
        // keeps tallying a corpse hides the outage.
        expect($result['workers'])->toBe(1)
            ->and($result['stale_workers'])->toBe(1)
            ->and($result['live'])->toBe(5);
    });

    it('surfaces the WORST worker\'s latency, not the average', function () {
        // Averaging hides the one worker stalling its users, which is exactly
        // the worker an operator needs to find.
        $dir = metricsDir();
        writeSnapshot($dir, 1, ['connect_ms' => ['count' => 5, 'p50' => 10.0, 'p95' => 20.0, 'max' => 25.0]]);
        writeSnapshot($dir, 2, ['connect_ms' => ['count' => 5, 'p50' => 900.0, 'p95' => 4000.0, 'max' => 5000.0]]);

        // toEqual, not toBe: snapshots round-trip through JSON, where a whole
        // float comes back as an int. The reader's contract admits float|int
        // for exactly that reason.
        expect((new MetricsReader($dir))->read()['connect_ms']['p95'])->toEqual(4000);
    });

    it('derives capacity from workers that are alive, not from the configured count', function () {
        // Configured 3 workers, one dead: capacity is 2 workers' worth, or the
        // dashboard would promise headroom that does not exist.
        config()->set('web-terminal-stream.stream.max_connections', 50);

        $dir = metricsDir();
        writeSnapshot($dir, 1, ['live' => 10]);
        writeSnapshot($dir, 2, ['live' => 10]);
        writeSnapshot($dir, 3, ['updated_at' => time() - 3600]);

        $result = (new MetricsReader($dir, staleAfterSeconds: 30))->read();

        expect($result['capacity'])->toBe(100)
            ->and($result['saturation'])->toBe(0.2);
    });

    it('sums live sessions by type across the fleet', function () {
        $dir = metricsDir();
        writeSnapshot($dir, 1, ['live_by_type' => ['ssh' => 3, 'local' => 1]]);
        writeSnapshot($dir, 2, ['live_by_type' => ['ssh' => 2]]);

        expect((new MetricsReader($dir))->read()['live_by_type'])->toBe(['ssh' => 5, 'local' => 1]);
    });

    it('ignores a half-written or corrupt snapshot instead of throwing', function () {
        $dir = metricsDir();
        writeSnapshot($dir, 1);
        file_put_contents($dir.'/metrics-2.json', '{not json');

        expect((new MetricsReader($dir))->read()['workers'])->toBe(1);
    });
});
