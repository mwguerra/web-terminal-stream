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

    /**
     * Whether the deprecated startConnected()/autoConnect() setters were
     * called. Lets getEffectiveConnectionBehavior() distinguish "legacy flags
     * explicitly set" from "nothing configured" (which defaults to Always,
     * the extraction-era always-auto-connect behavior).
     */
    protected bool $connectionFlagsTouched = false;

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
        $this->chromeExplicitlySet = true;

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
     * Whether the user declared a connection behavior explicitly — via
     * connectionBehavior() or the deprecated startConnected()/autoConnect()
     * flags. Used by containers (e.g. TerminalGrid) that only forward their
     * own behavior to children that didn't choose one.
     */
    public function hasExplicitConnectionBehavior(): bool
    {
        return $this->connectionBehavior !== null || $this->connectionFlagsTouched;
    }

    /**
     * The behavior the rendered terminal actually uses.
     *
     * Resolution order: explicit connectionBehavior() > deprecated
     * startConnected()/autoConnect() flags > Always (the default —
     * matches the extraction-era always-auto-connect behavior, so adding
     * the manual-connect UI is not a breaking change).
     */
    public function getEffectiveConnectionBehavior(): ConnectionBehavior
    {
        if ($this->connectionBehavior !== null) {
            return $this->connectionBehavior;
        }

        if ($this->connectionFlagsTouched) {
            return match (true) {
                $this->getAutoConnect() => ConnectionBehavior::Always,
                $this->getStartConnected() => ConnectionBehavior::Auto,
                default => ConnectionBehavior::Manual,
            };
        }

        return ConnectionBehavior::Always;
    }

    /**
     * @deprecated since 2.x, will be removed in 3.0.
     *             Use `->connectionBehavior(ConnectionBehavior::Auto|Manual)` instead.
     */
    public function startConnected(bool|Closure $startConnected = true): static
    {
        $this->emitDeprecationNotice('startConnected()', 'connectionBehavior(ConnectionBehavior::Auto)');
        $this->startConnected = $startConnected;
        $this->connectionFlagsTouched = true;

        return $this;
    }

    public function getStartConnected(): bool
    {
        return $this->evaluate($this->startConnected);
    }

    /**
     * @deprecated since 2.x, will be removed in 3.0.
     *             Use `->connectionBehavior(ConnectionBehavior::Always)` instead.
     */
    public function autoConnect(bool|Closure $autoConnect = true): static
    {
        $this->emitDeprecationNotice('autoConnect()', 'connectionBehavior(ConnectionBehavior::Always)');
        $this->autoConnect = $autoConnect;
        $this->connectionFlagsTouched = true;

        return $this;
    }

    public function getAutoConnect(): bool
    {
        return $this->evaluate($this->autoConnect);
    }
}
