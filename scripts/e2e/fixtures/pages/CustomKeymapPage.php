<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use MWGuerra\WebTerminalStream\Data\Keymap;
use MWGuerra\WebTerminalStream\Enums\PaneAction;
use MWGuerra\WebTerminalStream\Filament\Pages\Terminal;
use MWGuerra\WebTerminalStream\Schemas\Components\TerminalWorkspace;

/**
 * E2E fixture: a workspace with a fully custom keymap built fluently from
 * the default (tmux) preset. Demonstrates changing the prefix and binding
 * directional pane creation + close. SSH to the docker sshd container only.
 */
class CustomKeymapPage extends Terminal
{
    protected static ?string $slug = 'e2e-custom-keymap';

    protected static ?string $navigationLabel = 'E2E Custom Keymap';

    public function getSubheading(): string|Htmlable|null
    {
        return new HtmlString(
            'Custom keymap. Press the prefix <kbd>Ctrl</kbd>+<kbd>D</kbd>, then: '
            .'<kbd>Ctrl</kbd>+<kbd>&larr;/&rarr;/&uarr;/&darr;</kbd> add a pane in that direction · '
            .'<kbd>Ctrl</kbd>+<kbd>Q</kbd> close the current pane (never the last) · '
            .'<kbd>z</kbd> zoom · <kbd>arrows</kbd> move focus.'
        );
    }

    public function schema(Schema $schema): Schema
    {
        return $schema
            ->components([
                TerminalWorkspace::make()
                    ->key('e2e-custom-keymap')
                    ->ssh(host: '127.0.0.1', username: 'wts', password: 'wts-secret', port: 2299)
                    ->height('70vh')
                    ->maxPanes(8)
                    ->keymap(
                        // Start from the current default (tmux) keymap...
                        Keymap::default()
                            // ...change the prefix Ctrl+B → Ctrl+D...
                            ->prefix('ctrl+d')
                            // ...free Ctrl+arrows (default: resize) for creating panes...
                            ->unbind(PaneAction::ResizeLeft)
                            ->unbind(PaneAction::ResizeRight)
                            ->unbind(PaneAction::ResizeUp)
                            ->unbind(PaneAction::ResizeDown)
                            // ...add a pane in the arrow's direction from the current one...
                            ->bind(PaneAction::SplitLeft, 'ctrl+arrowleft')
                            ->bind(PaneAction::SplitRight, 'ctrl+arrowright')
                            ->bind(PaneAction::SplitUp, 'ctrl+arrowup')
                            ->bind(PaneAction::SplitDown, 'ctrl+arrowdown')
                            // ...and close the current pane with Ctrl+Q.
                            ->bind(PaneAction::ClosePane, 'ctrl+q')
                    ),
            ]);
    }
}
