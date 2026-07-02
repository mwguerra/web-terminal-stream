<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use Filament\Schemas\Schema;
use MWGuerra\WebTerminalStream\Filament\Pages\Terminal;
use MWGuerra\WebTerminalStream\Schemas\Components\TerminalGrid;
use MWGuerra\WebTerminalStream\Schemas\Components\WebTerminalStream;

/**
 * E2E fixture: static two-pane TerminalGrid. SSH to the docker sshd
 * container only.
 */
class GridPage extends Terminal
{
    protected static ?string $slug = 'e2e-grid';

    protected static ?string $navigationLabel = 'E2E Grid';

    public function schema(Schema $schema): Schema
    {
        return $schema
            ->components([
                TerminalGrid::make()
                    ->panes([
                        WebTerminalStream::make()
                            ->key('e2e-grid-pane-1')
                            ->ssh(host: '127.0.0.1', username: 'wts', password: 'wts-secret', port: 2299),
                        WebTerminalStream::make()
                            ->key('e2e-grid-pane-2')
                            ->ssh(host: '127.0.0.1', username: 'wts', password: 'wts-secret', port: 2299),
                    ])
                    ->columns(2)
                    ->height('400px'),
            ]);
    }
}
