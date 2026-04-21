<?php

declare(strict_types=1);

namespace MWGuerra\WebTerminal\Concerns;

use Closure;
use MWGuerra\WebTerminal\Enums\TerminalMode;

/**
 * Fluent configuration for the Classic/Stream mode selection and the
 * Stream terminal's visual theme.
 *
 * NOTE: the current API surface exposes three orthogonal knobs
 * (`streamTerminal`, `classicTerminal`, `defaultMode`) which is the main
 * source of the "did I enable stream-only or dual-mode?" confusion called
 * out in the branch plan. Stage 5 will introduce `->mode(TerminalMode)` as
 * the blessed replacement; the current methods here will become deprecated
 * aliases that delegate to `mode()`.
 *
 * @internal Shared trait.
 */
trait ConfiguresStreamMode
{
    protected bool|Closure $streamEnabled = false;

    protected bool|Closure $classicEnabled = true;

    protected TerminalMode $defaultMode = TerminalMode::Classic;

    /** @var array<string, mixed>|Closure */
    protected array|Closure $streamTheme = [];

    public function streamTerminal(bool|Closure $enabled = true): static
    {
        $this->streamEnabled = $enabled;

        return $this;
    }

    public function getStreamEnabled(): bool
    {
        return $this->evaluate($this->streamEnabled);
    }

    public function classicTerminal(bool|Closure $enabled = true): static
    {
        $this->classicEnabled = $enabled;

        return $this;
    }

    public function getClassicEnabled(): bool
    {
        return $this->evaluate($this->classicEnabled);
    }

    public function defaultMode(TerminalMode $mode): static
    {
        $this->defaultMode = $mode;

        return $this;
    }

    public function getDefaultMode(): TerminalMode
    {
        return $this->defaultMode;
    }

    /**
     * @param array<string, mixed>|Closure $theme
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
