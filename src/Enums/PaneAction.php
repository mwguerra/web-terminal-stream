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
    /** Split the focused pane side-by-side, new pane on the right (tmux `%`). */
    case SplitHorizontal = 'split_horizontal';

    /** Split the focused pane stacked, new pane below (tmux `"`). */
    case SplitVertical = 'split_vertical';

    /*
     * Directional splits: the new pane lands in the named direction from the
     * focused pane. Left/Up insert the new pane *before* its sibling; Right/
     * Down insert it *after* (the same side as SplitHorizontal/SplitVertical).
     * Unbound in the default keymap — opt in via keymap()/config.
     */
    case SplitLeft = 'split_left';

    case SplitRight = 'split_right';

    case SplitUp = 'split_up';

    case SplitDown = 'split_down';

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
