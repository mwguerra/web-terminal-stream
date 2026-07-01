<?php

declare(strict_types=1);

namespace MWGuerra\WebTerminalStream\Enums;

/**
 * How much surrounding UI the terminal renders.
 *
 * Replaces the boolean `windowControls()` setter, which only toggled the
 * three colored dots. The chrome enum lets callers also opt out of the
 * entire header bar for an embedded/frameless look.
 */
enum TerminalChrome: string
{
    /** Full chrome: header bar with window controls, title, and action buttons. Default. */
    case Full = 'full';

    /** Minimal chrome: header bar with title and actions, no window-control dots. */
    case Minimal = 'minimal';

    /**
     * No chrome: the header bar is hidden. Actions (scripts, copy, info) render
     * as a floating overlay in the terminal's top-right corner instead.
     */
    case None = 'none';

    public function showsWindowControls(): bool
    {
        return $this === self::Full;
    }

    public function showsHeader(): bool
    {
        return $this !== self::None;
    }
}
