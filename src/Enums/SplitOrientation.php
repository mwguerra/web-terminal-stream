<?php

declare(strict_types=1);

namespace MWGuerra\WebTerminalStream\Enums;

/**
 * The direction a pane splits in.
 *
 * Naming follows tmux: Horizontal places the panes side-by-side
 * (`split-window -h`, prefix `%` — the divider is vertical), Vertical
 * stacks them (`split-window -v`, prefix `"`).
 */
enum SplitOrientation: string
{
    case Horizontal = 'horizontal';

    case Vertical = 'vertical';
}
