<?php

declare(strict_types=1);

namespace MWGuerra\WebTerminal\Concerns;

use Closure;

/**
 * Fluent configuration for a terminal's visual shell:
 * size, title, window controls, and initial connect behavior.
 *
 * Consumed by both `Schemas\Components\WebTerminal` and
 * `Livewire\TerminalBuilder`. All fields accept a Closure so values
 * can be resolved at render time (e.g. from the authenticated user).
 *
 * @internal Shared trait — do not rely on its exact shape outside this
 *           package. Prefer the public fluent methods on the classes
 *           that consume it.
 */
trait ConfiguresTerminalAppearance
{
    protected string|Closure $height = '350px';

    protected string|Closure $title = 'Terminal';

    protected bool|Closure $showWindowControls = true;

    protected bool|Closure $startConnected = false;

    protected bool|Closure $autoConnect = false;

    public function height(string|Closure $height): static
    {
        $this->height = $height;

        return $this;
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

    public function windowControls(bool|Closure $show = true): static
    {
        $this->showWindowControls = $show;

        return $this;
    }

    public function getShowWindowControls(): bool
    {
        return $this->evaluate($this->showWindowControls);
    }

    /**
     * Start the terminal already connected on mount.
     *
     * The connect/disconnect button stays visible; the user can still disconnect
     * manually. For a button-hidden, session-persistent variant use `autoConnect()`.
     */
    public function startConnected(bool|Closure $startConnected = true): static
    {
        $this->startConnected = $startConnected;

        return $this;
    }

    public function getStartConnected(): bool
    {
        return $this->evaluate($this->startConnected);
    }

    /**
     * Enable auto-connect mode: connects on mount and hides the
     * connect/disconnect button so the session persists for the view.
     *
     * Implies `startConnected(true)` downstream; setting both is equivalent
     * to setting `autoConnect(true)` alone.
     */
    public function autoConnect(bool|Closure $autoConnect = true): static
    {
        $this->autoConnect = $autoConnect;

        return $this;
    }

    public function getAutoConnect(): bool
    {
        return $this->evaluate($this->autoConnect);
    }
}
