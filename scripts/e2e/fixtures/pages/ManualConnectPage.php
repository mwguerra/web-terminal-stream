<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use Filament\Schemas\Schema;
use MWGuerra\WebTerminalStream\Enums\ConnectionBehavior;
use MWGuerra\WebTerminalStream\Filament\Pages\Terminal;
use MWGuerra\WebTerminalStream\Schemas\Components\WebTerminalStream;

/**
 * E2E fixture: manual connection behavior — no WebSocket may open until the
 * user clicks the Connect overlay. SSH to the docker sshd container only.
 */
class ManualConnectPage extends Terminal
{
    protected static ?string $slug = 'e2e-manual';

    protected static ?string $navigationLabel = 'E2E Manual Connect';

    public function schema(Schema $schema): Schema
    {
        return $schema
            ->components([
                WebTerminalStream::make()
                    ->key('e2e-manual-terminal')
                    ->ssh(host: '127.0.0.1', username: 'wts', password: 'wts-secret', port: 2299)
                    ->connectionBehavior(ConnectionBehavior::Manual)
                    ->height('400px')
                    ->title('E2E Manual Terminal'),
            ]);
    }
}
