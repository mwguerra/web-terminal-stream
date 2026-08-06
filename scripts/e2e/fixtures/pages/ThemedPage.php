<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use MWGuerra\WebTerminalStream\Filament\Pages\Terminal;
use MWGuerra\WebTerminalStream\Schemas\Components\TerminalWorkspace;
use MWGuerra\WebTerminalStream\Themes\TokyoNight;

/**
 * E2E fixture: a themed workspace. Demonstrates the theme system — a
 * shipped preset (TokyoNight) used as-is but tweaked fluently for just the
 * font size and divider width/color, keeping every other preset default.
 * SSH to the docker sshd container only.
 */
class ThemedPage extends Terminal
{
    protected static ?string $slug = 'e2e-themed';

    protected static ?string $navigationLabel = 'E2E Themed';

    public function getSubheading(): string|Htmlable|null
    {
        return new HtmlString(
            'Themed workspace: the shipped <code>TokyoNight</code> preset with only '
            .'the font size and divider bumped fluently — every other default kept. '
            .'Split with the tmux prefix (<kbd>Ctrl</kbd>+<kbd>B</kbd>, then <kbd>%</kbd>/<kbd>&quot;</kbd>).'
        );
    }

    public function schema(Schema $schema): Schema
    {
        return $schema
            ->components([
                TerminalWorkspace::make()
                    ->key('e2e-themed')
                    ->ssh(host: '127.0.0.1', username: 'wts', password: 'wts-secret', port: 2299)
                    ->height('70vh')
                    ->maxPanes(8)
                    // Shipped preset, tweaked in place — keeps TokyoNight's
                    // colors/cursor/selection, overrides just these three.
                    ->theme(
                        TokyoNight::make()
                            ->fontFamily('JetBrains Mono, ui-monospace, monospace')
                            ->fontSize(15)
                            ->dividerWidth(3)
                            ->dividerColor('#7aa2f7')
                    ),
            ]);
    }
}
