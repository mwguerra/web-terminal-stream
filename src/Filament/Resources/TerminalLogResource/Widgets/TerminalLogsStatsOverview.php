<?php

declare(strict_types=1);

namespace MWGuerra\WebTerminalStream\Filament\Resources\TerminalLogResource\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use MWGuerra\WebTerminalStream\Models\TerminalLog;

class TerminalLogsStatsOverview extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        return [
            Stat::make(__('web-terminal-stream::terminal.widgets.total_logs'), number_format(TerminalLog::count()))
                ->description(__('web-terminal-stream::terminal.widgets.all_terminal_log_entries'))
                ->descriptionIcon('heroicon-m-document-text')
                ->color('primary'),

            Stat::make(__('web-terminal-stream::terminal.widgets.today'), number_format(TerminalLog::whereDate('created_at', today())->count()))
                ->description(__('web-terminal-stream::terminal.widgets.logs_created_today'))
                ->descriptionIcon('heroicon-m-calendar')
                ->color('success'),

            Stat::make(
                __('web-terminal-stream::terminal.events.connected'),
                number_format(TerminalLog::query()->where('event_type', TerminalLog::EVENT_CONNECTED)->count())
            )
                ->descriptionIcon('heroicon-m-arrow-right-on-rectangle')
                ->color('info'),

            Stat::make(
                __('web-terminal-stream::terminal.events.disconnected'),
                number_format(TerminalLog::query()->where('event_type', TerminalLog::EVENT_DISCONNECTED)->count())
            )
                ->descriptionIcon('heroicon-m-arrow-left-on-rectangle')
                ->color('warning'),
        ];
    }
}
