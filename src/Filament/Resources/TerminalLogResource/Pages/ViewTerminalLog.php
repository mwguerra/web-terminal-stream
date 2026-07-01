<?php

declare(strict_types=1);

namespace MWGuerra\WebTerminalStream\Filament\Resources\TerminalLogResource\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use MWGuerra\WebTerminalStream\Filament\Resources\TerminalLogResource;

class ViewTerminalLog extends ViewRecord
{
    protected static string $resource = TerminalLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')
                ->label(__('web-terminal-stream::terminal.resource.back'))
                ->icon('heroicon-o-arrow-left')
                ->url(TerminalLogResource::getUrl('index'))
                ->color('gray'),
        ];
    }
}
