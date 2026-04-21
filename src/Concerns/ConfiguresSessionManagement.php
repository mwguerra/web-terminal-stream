<?php

declare(strict_types=1);

namespace MWGuerra\WebTerminal\Concerns;

use Closure;

/**
 * Fluent configuration for terminal session lifecycle:
 * auto-disconnect on page navigation and inactivity timeout.
 *
 * Nullable fields let the downstream Livewire component fall back to
 * the package-wide defaults in `config/web-terminal.session.*`.
 *
 * @internal Shared trait.
 */
trait ConfiguresSessionManagement
{
    protected bool|Closure|null $disconnectOnNavigate = null;

    protected int|Closure|null $inactivityTimeout = null;

    public function disconnectOnNavigate(bool|Closure $enabled = true): static
    {
        $this->disconnectOnNavigate = $enabled;

        return $this;
    }

    public function keepConnectedOnNavigate(): static
    {
        $this->disconnectOnNavigate = false;

        return $this;
    }

    public function getDisconnectOnNavigate(): ?bool
    {
        if ($this->disconnectOnNavigate === null) {
            return null;
        }

        return $this->evaluate($this->disconnectOnNavigate);
    }

    public function inactivityTimeout(int|Closure $seconds): static
    {
        $this->inactivityTimeout = $seconds;

        return $this;
    }

    public function noInactivityTimeout(): static
    {
        $this->inactivityTimeout = 0;

        return $this;
    }

    public function getInactivityTimeout(): ?int
    {
        if ($this->inactivityTimeout === null) {
            return null;
        }

        return $this->evaluate($this->inactivityTimeout);
    }
}
