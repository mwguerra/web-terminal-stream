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
        $rows = [
            ['<kbd>Ctrl</kbd>+<kbd>D</kbd>', 'Prefix — press first, then a key below'],
            ['<kbd>&larr;</kbd> <kbd>&rarr;</kbd> <kbd>&uarr;</kbd> <kbd>&darr;</kbd>', 'Add a pane in that direction'],
            ['<kbd>h</kbd> <kbd>j</kbd> <kbd>k</kbd> <kbd>l</kbd>', 'Move focus between panes'],
            ['<kbd>Ctrl</kbd>+<kbd>Q</kbd>', 'Close the current pane (never the last)'],
            ['<kbd>z</kbd>', 'Zoom the focused pane'],
            ['<kbd>Ctrl</kbd>+<kbd>arrows</kbd>', 'Resize the focused pane'],
        ];

        // Inline styles (not Tailwind utilities) — this HTML is injected into
        // the subheading and never scanned by the app's Tailwind build, so
        // utility classes would be stripped/absent.
        $keyStyle = 'padding: 2px 24px 2px 0; white-space: nowrap; vertical-align: top;';
        $descStyle = 'padding: 2px 0;';

        $body = collect($rows)
            ->map(fn (array $r): string => "<tr><td style=\"{$keyStyle}\">{$r[0]}</td><td style=\"{$descStyle}\">{$r[1]}</td></tr>")
            ->implode('');

        return new HtmlString(
            '<table style="font-size: 0.875rem; border-collapse: collapse;"><tbody>'.$body.'</tbody></table>'
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
                            // ...move focus off the arrows onto hjkl so the
                            // arrows are free to create panes...
                            ->bind(PaneAction::FocusLeft, 'h')
                            ->bind(PaneAction::FocusRight, 'l')
                            ->bind(PaneAction::FocusUp, 'k')
                            ->bind(PaneAction::FocusDown, 'j')
                            // ...plain arrows add a pane in that direction...
                            ->bind(PaneAction::SplitLeft, 'arrowleft')
                            ->bind(PaneAction::SplitRight, 'arrowright')
                            ->bind(PaneAction::SplitUp, 'arrowup')
                            ->bind(PaneAction::SplitDown, 'arrowdown')
                            // ...and close the current pane with Ctrl+Q.
                            // (Ctrl+arrows keep the default resize binding.)
                            ->bind(PaneAction::ClosePane, 'ctrl+q')
                    ),
            ]);
    }
}
