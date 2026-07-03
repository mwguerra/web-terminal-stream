<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use MWGuerra\WebTerminalStream\Filament\Pages\Terminal;
use MWGuerra\WebTerminalStream\Schemas\Components\TerminalDashboard;
use MWGuerra\WebTerminalStream\Schemas\Components\WebTerminalStream;

/**
 * E2E fixture: a toggle-driven dashboard. Four buttons, each connected to a
 * distinct docker sshd container (ports 2299–2302); clicking one opens or
 * closes that terminal (destroying its PTY). Up to four open at once,
 * auto-arranged by a per-count layout map.
 */
class DashboardPage extends Terminal
{
    protected static ?string $slug = 'e2e-dashboard';

    protected static ?string $navigationLabel = 'E2E Dashboard';

    public function getSubheading(): string|Htmlable|null
    {
        return new HtmlString(
            'Each button opens/closes a terminal on a <em>distinct</em> docker container. '
            .'Up to four at once; the space auto-splits by how many are open '
            .'(2 = columns, 3 = one big + two stacked, 4 = 2&times;2 grid).'
        );
    }

    public function schema(Schema $schema): Schema
    {
        $source = fn (string $label, int $port): WebTerminalStream => WebTerminalStream::make()
            ->title($label)
            ->ssh(host: '127.0.0.1', username: 'wts', password: 'wts-secret', port: $port);

        return $schema
            ->components([
                TerminalDashboard::make()
                    ->key('e2e-dashboard')
                    ->height('70vh')
                    ->maxOpen(4)
                    ->sources([
                        'alpha' => $source('Alpha', 2299),
                        'bravo' => $source('Bravo', 2300),
                        'charlie' => $source('Charlie', 2301),
                        'delta' => $source('Delta', 2302),
                    ])
                    ->defaultOpen(['alpha'])
                    // How the space splits per open-pane count (default: tiled).
                    ->arrangement([
                        2 => 'columns',
                        3 => 'main-left',
                        4 => 'tiled',
                    ], default: 'tiled'),
            ]);
    }
}
