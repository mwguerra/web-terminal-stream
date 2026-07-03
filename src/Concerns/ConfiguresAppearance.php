<?php

declare(strict_types=1);

namespace MWGuerra\WebTerminalStream\Concerns;

use Closure;
use MWGuerra\WebTerminalStream\Enums\TerminalChrome;
use MWGuerra\WebTerminalStream\Themes\TerminalTheme;

/**
 * Fluent configuration for a terminal's visual shell: size, title,
 * theme, chrome level, and corner treatment.
 *
 * Consumed by both `Schemas\Components\WebTerminalStream` and
 * `Livewire\TerminalBuilder`. All fields accept a Closure so values
 * can be resolved at render time (e.g. from the authenticated user).
 *
 * @internal Shared trait — do not rely on its exact shape outside this
 *           package. Prefer the public fluent methods on the classes
 *           that consume it.
 */
trait ConfiguresAppearance
{
    protected string|Closure $height = '400px';

    protected string|Closure $title = 'Terminal';

    /** @var TerminalTheme|array<string, mixed>|Closure */
    protected TerminalTheme|array|Closure $theme = [];

    protected TerminalChrome|Closure $chrome = TerminalChrome::Full;

    protected bool|Closure $squareCorners = false;

    /*
     * Explicit-setting markers. Containers that apply defaults to child
     * terminals (e.g. TerminalGrid auto-applying frameless/squareCorners)
     * use these to leave explicitly configured children alone.
     */
    protected bool $heightExplicitlySet = false;

    protected bool $chromeExplicitlySet = false;

    protected bool $squareCornersExplicitlySet = false;

    public function height(string|Closure $height): static
    {
        $this->height = $height;
        $this->heightExplicitlySet = true;

        return $this;
    }

    public function hasExplicitHeight(): bool
    {
        return $this->heightExplicitlySet;
    }

    public function getHeight(): string
    {
        return $this->evaluate($this->height);
    }

    public function title(string|Closure $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getTitle(): string
    {
        return $this->evaluate($this->title);
    }

    /**
     * The terminal look. Pass a `TerminalTheme` (or a preset/subclass) for
     * font + colors + divider styling, or a raw colors array for just the
     * ghostty-web `Terminal` theme.
     *
     * @param  TerminalTheme|array<string, mixed>|Closure  $theme
     */
    public function theme(TerminalTheme|array|Closure $theme): static
    {
        $this->theme = $theme;

        return $this;
    }

    /**
     * The resolved theme as a `TerminalTheme`, or null when only a raw
     * colors array (or nothing) was configured. Containers read this for
     * divider CSS variables.
     */
    public function getThemeObject(): ?TerminalTheme
    {
        $theme = $this->evaluate($this->theme);

        return $theme instanceof TerminalTheme ? $theme : null;
    }

    /**
     * The ghostty-web `Terminal` theme (colors only).
     *
     * @return array<string, mixed>
     */
    public function getTheme(): array
    {
        $theme = $this->evaluate($this->theme);

        return $theme instanceof TerminalTheme ? $theme->toColors() : $theme;
    }

    /**
     * Terminal font family — null falls back to the view default.
     */
    public function getFontFamily(): ?string
    {
        return $this->getThemeObject()?->getFontFamily();
    }

    /**
     * Terminal font size in px — null falls back to the view default.
     */
    public function getFontSize(): ?int
    {
        return $this->getThemeObject()?->getFontSize();
    }

    /**
     * Set the terminal chrome level.
     *
     * - `TerminalChrome::Full` — header bar with window-control dots, title, and actions.
     * - `TerminalChrome::Minimal` — header bar without the dots.
     * - `TerminalChrome::None` — no header; actions move to floating controls.
     */
    public function chrome(TerminalChrome|Closure $chrome): static
    {
        $this->chrome = $chrome;
        $this->chromeExplicitlySet = true;

        return $this;
    }

    public function hasExplicitChrome(): bool
    {
        return $this->chromeExplicitlySet;
    }

    public function getChrome(): TerminalChrome
    {
        return $this->evaluate($this->chrome);
    }

    /**
     * Shorthand for `->chrome(TerminalChrome::None)`. The terminal renders
     * as a plain surface with no header or footer — suitable for embedding
     * inside custom layouts.
     */
    public function frameless(): static
    {
        return $this->chrome(TerminalChrome::None);
    }

    /**
     * Drop the terminal's outer border-radius so it can sit flush against
     * neighbouring tiles in a grid. Off by default; the standard rounded
     * card look is preserved unless you opt in.
     */
    public function squareCorners(bool|Closure $enabled = true): static
    {
        $this->squareCorners = $enabled;
        $this->squareCornersExplicitlySet = true;

        return $this;
    }

    public function hasExplicitSquareCorners(): bool
    {
        return $this->squareCornersExplicitlySet;
    }

    public function getSquareCorners(): bool
    {
        return (bool) $this->evaluate($this->squareCorners);
    }
}
