<?php

declare(strict_types=1);

namespace MWGuerra\WebTerminalStream\Concerns;

use Closure;
use MWGuerra\WebTerminalStream\Enums\ConnectionBehavior;
use MWGuerra\WebTerminalStream\Enums\TerminalChrome;

/**
 * Fluent configuration for a terminal's visual shell:
 * size, title, chrome level, and initial connect behavior.
 *
 * Consumed by both `Schemas\Components\WebTerminalStream` and
 * `Livewire\TerminalBuilder`. All fields accept a Closure so values
 * can be resolved at render time (e.g. from the authenticated user).
 *
 * @internal Shared trait — do not rely on its exact shape outside this
 *           package. Prefer the public fluent methods on the classes
 *           that consume it.
 */
trait ConfiguresTerminalAppearance
{
    use EmitsDeprecationNotices;

    protected string|Closure $height = '350px';

    protected string|Closure $title = 'Terminal';

    protected TerminalChrome|Closure $chrome = TerminalChrome::Full;

    protected bool|Closure $squareCorners = false;

    protected ?ConnectionBehavior $connectionBehavior = null;

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

        return $this;
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

        return $this;
    }

    public function getSquareCorners(): bool
    {
        return (bool) $this->evaluate($this->squareCorners);
    }

    /**
     * @deprecated since 2.x, will be removed in 3.0.
     *             Use `->chrome(TerminalChrome::Full|Minimal)` instead.
     */
    public function windowControls(bool|Closure $show = true): static
    {
        $this->emitDeprecationNotice('windowControls()', 'chrome(TerminalChrome::Full|Minimal)');

        // Preserve previous semantics: windowControls(true) == Full, (false) == Minimal.
        // Callers that want truly frameless must opt in explicitly via chrome()/frameless().
        $resolver = function () use ($show): TerminalChrome {
            return ($this->evaluate($show))
                ? TerminalChrome::Full
                : TerminalChrome::Minimal;
        };
        $this->chrome = $resolver;

        return $this;
    }

    public function getShowWindowControls(): bool
    {
        return $this->getChrome()->showsWindowControls();
    }

    /**
     * Declarative connection behavior. Preferred over the individual
     * `startConnected()` / `autoConnect()` setters.
     */
    public function connectionBehavior(ConnectionBehavior $behavior): static
    {
        $this->connectionBehavior = $behavior;

        $flags = $behavior->toFlags();
        $this->startConnected = $flags['startConnected'];
        $this->autoConnect = $flags['autoConnect'];

        return $this;
    }

    public function getConnectionBehavior(): ?ConnectionBehavior
    {
        return $this->connectionBehavior;
    }

    /**
     * @deprecated since 2.x, will be removed in 3.0.
     *             Use `->connectionBehavior(ConnectionBehavior::AutoWithButton|Manual)` instead.
     */
    public function startConnected(bool|Closure $startConnected = true): static
    {
        $this->emitDeprecationNotice('startConnected()', 'connectionBehavior(ConnectionBehavior::AutoWithButton)');
        $this->startConnected = $startConnected;

        return $this;
    }

    public function getStartConnected(): bool
    {
        return $this->evaluate($this->startConnected);
    }

    /**
     * @deprecated since 2.x, will be removed in 3.0.
     *             Use `->connectionBehavior(ConnectionBehavior::AutoHidden)` instead.
     */
    public function autoConnect(bool|Closure $autoConnect = true): static
    {
        $this->emitDeprecationNotice('autoConnect()', 'connectionBehavior(ConnectionBehavior::AutoHidden)');
        $this->autoConnect = $autoConnect;

        return $this;
    }

    public function getAutoConnect(): bool
    {
        return $this->evaluate($this->autoConnect);
    }
}
