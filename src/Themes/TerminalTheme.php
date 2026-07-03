<?php

declare(strict_types=1);

namespace MWGuerra\WebTerminalStream\Themes;

use Illuminate\Contracts\Support\Arrayable;

/**
 * The look of a terminal and its pane dividers, as an extendable set of
 * defaults with fluent partial overrides.
 *
 * Three ways to use it, from cheapest to richest:
 *
 *   // 1. Tweak the base defaults inline — only what you name changes.
 *   TerminalTheme::make()->background('#101014')->fontSize(15)
 *
 *   // 2. Start from a shipped preset and tweak one or two things.
 *   TokyoNight::make()->dividerWidth(2)
 *
 *   // 3. Ship your own theme as a subclass — override the defaults you
 *   //    care about, inherit the rest; it stays fluently tweakable.
 *   final class BrandTheme extends TerminalTheme
 *   {
 *       protected string $background = '#0b1021';
 *       protected string $foreground = '#c7d2fe';
 *       protected string $dividerColor = '#312e81';
 *   }
 *   BrandTheme::make()->fontFamily('Berkeley Mono, monospace')
 *
 * @implements Arrayable<string, mixed>
 */
class TerminalTheme implements Arrayable
{
    // ── Terminal font ───────────────────────────────────────────────────
    protected string $fontFamily = 'ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace';

    protected int $fontSize = 13;

    // ── Terminal colors (handed to the ghostty-web Terminal theme) ──────
    protected string $background = '#1a1a2e';

    protected string $foreground = '#e2e8f0';

    protected ?string $cursor = null;

    protected ?string $selectionBackground = null;

    /** @var array<string, string> Extra ghostty theme keys (ANSI palette, etc.) */
    protected array $palette = [];

    // ── Pane dividers (container CSS custom properties) ─────────────────
    protected int $dividerWidth = 1;

    protected string $dividerStyle = 'solid';

    protected string $dividerColor = 'rgba(148, 163, 184, 0.4)';

    /** Highlight for the focused pane's ring and the hovered/dragged divider. */
    protected string $dividerFocusColor = 'rgba(96, 165, 250, 0.7)';

    public static function make(): static
    {
        return new static;
    }

    // ── Fluent setters — each overrides one default, keeps the rest ─────

    public function fontFamily(string $fontFamily): static
    {
        $this->fontFamily = $fontFamily;

        return $this;
    }

    public function fontSize(int $pixels): static
    {
        $this->fontSize = max(1, $pixels);

        return $this;
    }

    public function background(string $color): static
    {
        $this->background = $color;

        return $this;
    }

    public function foreground(string $color): static
    {
        $this->foreground = $color;

        return $this;
    }

    public function cursor(?string $color): static
    {
        $this->cursor = $color;

        return $this;
    }

    public function selectionBackground(?string $color): static
    {
        $this->selectionBackground = $color;

        return $this;
    }

    /**
     * Merge extra ghostty theme keys (e.g. the 16-color ANSI palette:
     * black, red, green, ... brightWhite).
     *
     * @param  array<string, string>  $palette
     */
    public function palette(array $palette): static
    {
        $this->palette = [...$this->palette, ...$palette];

        return $this;
    }

    public function dividerWidth(int $pixels): static
    {
        $this->dividerWidth = max(0, $pixels);

        return $this;
    }

    public function dividerStyle(string $style): static
    {
        $this->dividerStyle = $style;

        return $this;
    }

    public function dividerColor(string $color): static
    {
        $this->dividerColor = $color;

        return $this;
    }

    public function dividerFocusColor(string $color): static
    {
        $this->dividerFocusColor = $color;

        return $this;
    }

    // ── Accessors ───────────────────────────────────────────────────────

    public function getFontFamily(): string
    {
        return $this->fontFamily;
    }

    public function getFontSize(): int
    {
        return $this->fontSize;
    }

    public function getBackground(): string
    {
        return $this->background;
    }

    public function getForeground(): string
    {
        return $this->foreground;
    }

    /**
     * The ghostty-web `Terminal` theme object (colors only).
     *
     * @return array<string, string>
     */
    public function toColors(): array
    {
        return [
            ...$this->palette,
            'background' => $this->background,
            'foreground' => $this->foreground,
            ...array_filter([
                'cursor' => $this->cursor,
                'selectionBackground' => $this->selectionBackground,
            ], fn (?string $value): bool => $value !== null),
        ];
    }

    /**
     * CSS custom properties for a workspace/grid container: divider
     * width/style/color, focus highlight, and the surface background.
     *
     * @return array<string, string>
     */
    public function toCssVariables(): array
    {
        return [
            '--wts-divider-width' => $this->dividerWidth.'px',
            '--wts-divider-style' => $this->dividerStyle,
            '--wts-divider-color' => $this->dividerColor,
            '--wts-divider-focus' => $this->dividerFocusColor,
            '--wts-terminal-bg' => $this->background,
        ];
    }

    /**
     * Emit `toCssVariables()` as an inline `style` declaration string.
     */
    public function toCssVariableString(): string
    {
        $declarations = [];

        foreach ($this->toCssVariables() as $property => $value) {
            $declarations[] = "{$property}: {$value};";
        }

        return implode(' ', $declarations);
    }

    /**
     * @return array{fontFamily: string, fontSize: int, colors: array<string, string>, css: array<string, string>}
     */
    public function toArray(): array
    {
        return [
            'fontFamily' => $this->fontFamily,
            'fontSize' => $this->fontSize,
            'colors' => $this->toColors(),
            'css' => $this->toCssVariables(),
        ];
    }
}
