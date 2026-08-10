<?php

declare(strict_types=1);

namespace MWGuerra\WebTerminalStream\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use MWGuerra\WebTerminalStream\Metrics\MetricsReader;

/**
 * Live health of the WebSocket terminal fleet.
 *
 * Reads what the workers publish (see {@see MetricsReader}); it never talks to
 * a server process, because the app and the terminal server are separate
 * programs — often on separate supervisors.
 *
 * The stats are chosen to answer the two operator questions that actually
 * matter, in order: "is it up?" and "when do I add capacity?" Everything here
 * degrades to an honest "unknown" rather than a reassuring zero, because a
 * dashboard that reads healthy while the daemon is dead is worse than no
 * dashboard.
 */
class TerminalServerHealthWidget extends StatsOverviewWidget
{
    /** Live numbers go stale fast; a static dashboard would mislead. */
    protected ?string $pollingInterval = '10s';

    protected function getStats(): array
    {
        $m = MetricsReader::make()->read();

        if (! $m['available']) {
            return [
                Stat::make(__('web-terminal-stream::terminal.metrics.fleet'), __('web-terminal-stream::terminal.metrics.no_workers'))
                    ->description(__('web-terminal-stream::terminal.metrics.no_workers_hint'))
                    ->descriptionIcon('heroicon-m-exclamation-triangle')
                    ->color('danger'),
            ];
        }

        $saturation = $m['saturation'];
        $percent = $saturation === null ? null : (int) round($saturation * 100);

        return [
            Stat::make(__('web-terminal-stream::terminal.metrics.live_sessions'), (string) $m['live'])
                ->description($this->capacityDescription($m, $percent))
                ->descriptionIcon('heroicon-m-command-line')
                ->color($this->saturationColour($percent)),

            Stat::make(__('web-terminal-stream::terminal.metrics.workers'), (string) $m['workers'])
                ->description($m['stale_workers'] > 0
                    ? __('web-terminal-stream::terminal.metrics.workers_missing', ['count' => $m['stale_workers']])
                    : __('web-terminal-stream::terminal.metrics.workers_healthy'))
                ->descriptionIcon($m['stale_workers'] > 0 ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-cpu-chip')
                ->color($m['stale_workers'] > 0 ? 'warning' : 'success'),

            // Connect is the blocking operation: while one session connects,
            // every other session on THAT worker is frozen. So this is not a
            // vanity latency number — it is how long other people wait.
            Stat::make(
                __('web-terminal-stream::terminal.metrics.connect_p95'),
                $this->ms($m['connect_ms']['p95'] ?? null)
            )
                ->description(__('web-terminal-stream::terminal.metrics.connect_hint'))
                ->descriptionIcon('heroicon-m-bolt')
                ->color($this->latencyColour($m['connect_ms']['p95'] ?? null, warn: 3000, bad: 10000)),

            // The loop's own heartbeat. Climbing sweeps mean a saturated worker
            // before any user has complained yet.
            Stat::make(
                __('web-terminal-stream::terminal.metrics.sweep_p95'),
                $this->ms($m['sweep_ms']['p95'] ?? null)
            )
                ->description(__('web-terminal-stream::terminal.metrics.sweep_hint'))
                ->descriptionIcon('heroicon-m-arrow-path')
                ->color($this->latencyColour($m['sweep_ms']['p95'] ?? null, warn: 100, bad: 500)),

            Stat::make(__('web-terminal-stream::terminal.metrics.refused'), (string) $m['refused_total'])
                ->description($m['refused_total'] > 0
                    ? __('web-terminal-stream::terminal.metrics.refused_hint')
                    : __('web-terminal-stream::terminal.metrics.refused_none'))
                ->descriptionIcon('heroicon-m-no-symbol')
                ->color($m['refused_total'] > 0 ? 'danger' : 'success'),
        ];
    }

    /**
     * @param  array<string, mixed>  $m
     */
    private function capacityDescription(array $m, ?int $percent): string
    {
        if ($percent === null) {
            return __('web-terminal-stream::terminal.metrics.capacity_unlimited');
        }

        return __('web-terminal-stream::terminal.metrics.capacity_used', [
            'percent' => $percent,
            'capacity' => $m['capacity'],
        ]);
    }

    /**
     * Thresholds are deliberately well below the hard cap: the terminal server
     * refuses at 100%, but the experience degrades long before that, so the
     * useful alarm is "add a worker", not "you are full".
     */
    private function saturationColour(?int $percent): string
    {
        return match (true) {
            $percent === null => 'gray',
            $percent >= 80 => 'danger',
            $percent >= 60 => 'warning',
            default => 'success',
        };
    }

    private function latencyColour(?float $value, float $warn, float $bad): string
    {
        return match (true) {
            $value === null => 'gray',
            $value >= $bad => 'danger',
            $value >= $warn => 'warning',
            default => 'success',
        };
    }

    private function ms(?float $value): string
    {
        if ($value === null) {
            return '—';
        }

        return $value >= 1000
            ? round($value / 1000, 1).' s'
            : (int) round($value).' ms';
    }
}
