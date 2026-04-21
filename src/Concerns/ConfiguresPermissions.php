<?php

declare(strict_types=1);

namespace MWGuerra\WebTerminal\Concerns;

use Closure;
use MWGuerra\WebTerminal\Enums\TerminalPermission;

/**
 * Fluent configuration for terminal permissions: command whitelist bypass,
 * interactive (PTY/tmux) mode, and shell-operator allowances.
 *
 * Two ways to set permissions:
 *   1. `allow([TerminalPermission::...])` — enum-based, recommended
 *   2. Individual methods: `allowPipes()`, `allowRedirection()`, etc.
 *
 * Both paths write to the same underlying fields, so they compose cleanly.
 *
 * Current shape preserves the existing v2 API. The v3 release is expected
 * to consolidate these around `allow()` + `deny()`.
 *
 * @internal Shared trait.
 */
trait ConfiguresPermissions
{
    use EmitsDeprecationNotices;

    protected bool|Closure $allowAllCommands = false;

    protected bool|Closure $allowInteractiveMode = false;

    protected bool|Closure $allowPipes = false;

    protected bool|Closure $allowRedirection = false;

    protected bool|Closure $allowChaining = false;

    protected bool|Closure $allowExpansion = false;

    protected bool|Closure $allowAllShellOperators = false;

    /**
     * Bypass the command whitelist. Use with caution.
     */
    public function allowAllCommands(bool|Closure $allowAll = true): static
    {
        $this->allowAllCommands = $allowAll;

        return $this;
    }

    /**
     * Back-compat name preserved — the short `getAllowAll()` reads
     * better than the paired setter's `allowAllCommands()` name.
     */
    public function getAllowAll(): bool
    {
        return $this->evaluate($this->allowAllCommands);
    }

    public function allowInteractiveMode(bool|Closure $allow = true): static
    {
        $this->allowInteractiveMode = $allow;

        return $this;
    }

    public function getAllowInteractiveMode(): bool
    {
        return $this->evaluate($this->allowInteractiveMode);
    }

    /**
     * @deprecated since 2.x, will be removed in 3.0.
     *             Use `->allow([TerminalPermission::Pipes])` instead.
     */
    public function allowPipes(bool|Closure $allow = true): static
    {
        $this->emitDeprecationNotice('allowPipes()', 'allow([TerminalPermission::Pipes])');
        $this->allowPipes = $allow;

        return $this;
    }

    public function getAllowPipes(): bool
    {
        return $this->evaluate($this->allowPipes);
    }

    /**
     * @deprecated since 2.x, will be removed in 3.0.
     *             Use `->allow([TerminalPermission::Redirection])` instead.
     */
    public function allowRedirection(bool|Closure $allow = true): static
    {
        $this->emitDeprecationNotice('allowRedirection()', 'allow([TerminalPermission::Redirection])');
        $this->allowRedirection = $allow;

        return $this;
    }

    public function getAllowRedirection(): bool
    {
        return $this->evaluate($this->allowRedirection);
    }

    /**
     * @deprecated since 2.x, will be removed in 3.0.
     *             Use `->allow([TerminalPermission::Chaining])` instead.
     */
    public function allowChaining(bool|Closure $allow = true): static
    {
        $this->emitDeprecationNotice('allowChaining()', 'allow([TerminalPermission::Chaining])');
        $this->allowChaining = $allow;

        return $this;
    }

    public function getAllowChaining(): bool
    {
        return $this->evaluate($this->allowChaining);
    }

    /**
     * @deprecated since 2.x, will be removed in 3.0.
     *             Use `->allow([TerminalPermission::Expansion])` instead.
     */
    public function allowExpansion(bool|Closure $allow = true): static
    {
        $this->emitDeprecationNotice('allowExpansion()', 'allow([TerminalPermission::Expansion])');
        $this->allowExpansion = $allow;

        return $this;
    }

    public function getAllowExpansion(): bool
    {
        return $this->evaluate($this->allowExpansion);
    }

    /**
     * Allow all shell operators (pipes + redirection + chaining + expansion).
     *
     * Writes the aggregate flag and all four individual flags so downstream
     * consumers reading any of them observe a consistent state.
     */
    public function allowAllShellOperators(bool|Closure $allow = true): static
    {
        $this->allowAllShellOperators = $allow;
        $this->allowPipes = $allow;
        $this->allowRedirection = $allow;
        $this->allowChaining = $allow;
        $this->allowExpansion = $allow;

        return $this;
    }

    public function getAllowAllShellOperators(): bool
    {
        return $this->evaluate($this->allowAllShellOperators);
    }

    /**
     * Enum-based permissions setup — the preferred entry point.
     *
     * @param array<TerminalPermission> $permissions
     */
    public function allow(array $permissions): static
    {
        $flags = TerminalPermission::resolveManyFlags($permissions);

        if ($flags['allowAllCommands'] ?? false) {
            $this->allowAllCommands = true;
        }
        if ($flags['allowPipes'] ?? false) {
            $this->allowPipes = true;
        }
        if ($flags['allowRedirection'] ?? false) {
            $this->allowRedirection = true;
        }
        if ($flags['allowChaining'] ?? false) {
            $this->allowChaining = true;
        }
        if ($flags['allowExpansion'] ?? false) {
            $this->allowExpansion = true;
        }
        if ($flags['allowAllShellOperators'] ?? false) {
            $this->allowAllShellOperators = true;
        }
        if ($flags['allowInteractiveMode'] ?? false) {
            $this->allowInteractiveMode = true;
        }

        return $this;
    }
}
