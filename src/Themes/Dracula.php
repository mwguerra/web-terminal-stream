<?php

declare(strict_types=1);

namespace MWGuerra\WebTerminalStream\Themes;

/**
 * Dracula — a shipped preset. See TokyoNight for the extension pattern.
 */
class Dracula extends TerminalTheme
{
    protected string $background = '#282a36';

    protected string $foreground = '#f8f8f2';

    protected ?string $cursor = '#f8f8f0';

    protected ?string $selectionBackground = '#44475a';

    protected string $dividerColor = 'rgba(68, 71, 90, 0.9)';

    protected string $dividerFocusColor = '#bd93f9';
}
