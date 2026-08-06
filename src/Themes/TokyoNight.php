<?php

declare(strict_types=1);

namespace MWGuerra\WebTerminalStream\Themes;

/**
 * Tokyo Night — a shipped preset. Overrides only the colors it needs and
 * inherits the base font/divider defaults; still fluently tweakable, e.g.
 * `TokyoNight::make()->fontSize(15)`.
 */
class TokyoNight extends TerminalTheme
{
    protected string $background = '#1a1b26';

    protected string $foreground = '#a9b1d6';

    protected ?string $cursor = '#c0caf5';

    protected ?string $selectionBackground = '#283457';

    protected string $dividerColor = 'rgba(65, 72, 104, 0.9)';

    protected string $dividerFocusColor = '#7aa2f7';
}
