<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use Filament\Schemas\Schema;
use MWGuerra\WebTerminalStream\Filament\Pages\Terminal;
use MWGuerra\WebTerminalStream\Schemas\Components\WebTerminalStream;

/**
 * E2E fixture: one auto-connecting terminal. SSH to the throwaway docker
 * sshd container ONLY — the e2e browser must never open a workstation shell.
 */
class SingleTerminalPage extends Terminal
{
    protected static ?string $slug = 'e2e-single';

    protected static ?string $navigationLabel = 'E2E Single';

    public function schema(Schema $schema): Schema
    {
        return $schema
            ->components([
                WebTerminalStream::make()
                    ->key('e2e-single-terminal')
                    ->ssh(host: '127.0.0.1', username: 'wts', password: 'wts-secret', port: 2299)
                    ->height('400px')
                    ->title('E2E Single Terminal'),
            ]);
    }
}
