<?php

declare(strict_types=1);

namespace MWGuerra\WebTerminalStream\Enums;

/**
 * The keyboard-drivable pane actions of a terminal workspace.
 *
 * Backed values are the keys used in the `workspace.shortcuts.bindings`
 * config array and in the keymap JSON shipped to the frontend.
 */
enum PaneAction: string
{
    /** Split the focused pane side-by-side (tmux `%`). */
    case SplitHorizontal = 'split_horizontal';

    /** Split the focused pane stacked (tmux `"`). */
    case SplitVertical = 'split_vertical';

    case ClosePane = 'close_pane';

    /** Toggle fullscreen for the focused pane (tmux `z`). */
    case ZoomPane = 'zoom_pane';

    case FocusLeft = 'focus_left';

    case FocusRight = 'focus_right';

    case FocusUp = 'focus_up';

    case FocusDown = 'focus_down';

    case ResizeLeft = 'resize_left';

    case ResizeRight = 'resize_right';

    case ResizeUp = 'resize_up';

    case ResizeDown = 'resize_down';
}
