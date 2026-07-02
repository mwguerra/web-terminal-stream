<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use Filament\Schemas\Schema;
use MWGuerra\WebTerminalStream\Data\Keymap;
use MWGuerra\WebTerminalStream\Filament\Pages\Terminal;
use MWGuerra\WebTerminalStream\Schemas\Components\TerminalWorkspace;

/**
 * E2E fixture: tmux-style dynamic workspace with the explicit tmux keymap
 * (prefix ctrl+b). SSH to the docker sshd container only.
 */
class WorkspacePage extends Terminal
{
    protected static ?string $slug = 'e2e-workspace';

    protected static ?string $navigationLabel = 'E2E Workspace';

    public function schema(Schema $schema): Schema
    {
        return $schema
            ->components([
                TerminalWorkspace::make()
                    ->key('e2e-workspace')
                    ->ssh(host: '127.0.0.1', username: 'wts', password: 'wts-secret', port: 2299)
                    ->height('70vh')
                    ->maxPanes(6)
                    ->keymap(Keymap::tmux()),
            ]);
    }
}
