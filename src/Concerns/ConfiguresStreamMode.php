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
    use EmitsDeprecationNotices;

    protected bool|Closure $streamEnabled = false;

    protected bool|Closure $classicEnabled = true;

    protected TerminalMode $defaultMode = TerminalMode::Classic;

    /** @var array<string, mixed>|Closure */
    protected array|Closure $streamTheme = [];

    /**
     * Single-call mode selector.
     *
     * - `TerminalMode::Classic` — Classic command-by-command (Livewire). Default.
     * - `TerminalMode::Stream` — Full interactive PTY via WebSocket.
     *
     * For the dual-mode container (both Classic + Stream with a toggle pill),
     * use the `dual()` method — `TerminalMode` only has two cases because
     * that's what a Livewire component can be *rendering*. The dual container
     * mounts both under the hood.
     */
    public function mode(TerminalMode $mode): static
    {
        if ($mode === TerminalMode::Stream) {
            $this->streamEnabled = true;
            $this->classicEnabled = false;
            $this->defaultMode = TerminalMode::Stream;
        } else {
            $this->streamEnabled = false;
            $this->classicEnabled = true;
            $this->defaultMode = TerminalMode::Classic;
        }

        return $this;
    }

    /**
     * Enable dual-mode (Classic + Stream) with the header toggle pill.
     */
    public function dual(TerminalMode $defaultMode = TerminalMode::Classic): static
    {
        $this->streamEnabled = true;
        $this->classicEnabled = true;
        $this->defaultMode = $defaultMode;

        return $this;
    }

    /**
     * @deprecated since 2.x, will be removed in 3.0.
     *             Use `->mode(TerminalMode::Stream)` for stream-only, or
     *             `->dual()` for dual-mode with a toggle. The old default
     *             of calling `streamTerminal()` alone silently produced
     *             dual-mode, which was a long-standing footgun.
     */
    public function streamTerminal(bool|Closure $enabled = true): static
    {
        $this->emitDeprecationNotice('streamTerminal()', 'mode(TerminalMode::Stream) or dual()');
        $this->streamEnabled = $enabled;

        return $this;
    }

    public function getStreamEnabled(): bool
    {
        return $this->evaluate($this->streamEnabled);
    }

    /**
     * @deprecated since 2.x, will be removed in 3.0.
     *             Use `->mode(TerminalMode::Stream)` or `->mode(TerminalMode::Classic)` instead.
     */
    public function classicTerminal(bool|Closure $enabled = true): static
    {
        $this->emitDeprecationNotice('classicTerminal()', 'mode(TerminalMode::Classic)');
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
