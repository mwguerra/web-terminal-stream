<?php

declare(strict_types=1);

namespace MWGuerra\WebTerminalStream\Concerns;

use Closure;
use MWGuerra\WebTerminalStream\Enums\ConnectionBehavior;

/**
 * Fluent configuration for how a terminal connects on mount.
 *
 * @internal Shared trait.
 */
trait ConfiguresConnectionLifecycle
{
    protected ConnectionBehavior|Closure|null $connectionBehavior = null;

    public function connectionBehavior(ConnectionBehavior|Closure $behavior): static
    {
        $this->connectionBehavior = $behavior;

        return $this;
    }

    /**
     * Whether the user declared a connection behavior explicitly. Used by
     * containers (e.g. TerminalGrid) that only forward their own behavior
     * to children that didn't choose one.
     */
    public function hasExplicitConnectionBehavior(): bool
    {
        return $this->connectionBehavior !== null;
    }

    /**
     * The behavior the rendered terminal uses. Defaults to Always
     * (auto-connect, no connect/disconnect UI).
     */
    public function getConnectionBehavior(): ConnectionBehavior
    {
        return $this->evaluate($this->connectionBehavior) ?? ConnectionBehavior::Always;
    }
}
