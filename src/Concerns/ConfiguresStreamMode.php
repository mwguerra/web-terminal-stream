<?php

declare(strict_types=1);

namespace MWGuerra\WebTerminalStream\Concerns;

use Closure;

/**
 * Fluent configuration for the Stream terminal's visual theme.
 *
 * The theme array is handed to the ghostty-web Terminal constructor
 * (background, foreground, fontSize, palette, ...).
 *
 * @internal Shared trait.
 */
trait ConfiguresStreamMode
{
    /** @var array<string, mixed>|Closure */
    protected array|Closure $streamTheme = [];

    /**
     * @param  array<string, mixed>|Closure  $theme
     */
    public function streamTheme(array|Closure $theme): static
    {
        $this->streamTheme = $theme;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getStreamTheme(): array
    {
        return $this->evaluate($this->streamTheme);
    }
}
