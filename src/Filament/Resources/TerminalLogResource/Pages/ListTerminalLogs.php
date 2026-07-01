<?php

declare(strict_types=1);

namespace MWGuerra\WebTerminalStream\Filament\Resources\TerminalLogResource\Pages;

use Filament\Resources\Pages\ListRecords;
use MWGuerra\WebTerminalStream\Filament\Resources\TerminalLogResource;
use MWGuerra\WebTerminalStream\Filament\Resources\TerminalLogResource\Widgets\TerminalLogsStatsOverview;

class ListTerminalLogs extends ListRecords
{
    protected static string $resource = TerminalLogResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            TerminalLogsStatsOverview::class,
        ];
    }
}
