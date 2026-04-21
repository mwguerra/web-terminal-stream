<?php

declare(strict_types=1);

namespace MWGuerra\WebTerminal\Concerns;

/**
 * Opinionated preset whitelists for common use cases.
 *
 * Each preset is a shortcut for `allowedCommands([...])` — use them when
 * the defaults are good enough and skip the boilerplate. For anything
 * custom, call `allowedCommands()` directly.
 *
 * Consuming classes must provide an `allowedCommands(array $commands): static`
 * method (both Schema\Components\WebTerminal and Livewire\TerminalBuilder do).
 *
 * @internal Shared trait.
 */
trait ConfiguresCommandPresets
{
    /** Read-only file inspection. */
    public function readOnly(): static
    {
        return $this->allowedCommands(['ls', 'pwd', 'cat', 'head', 'tail', 'find', 'grep']);
    }

    /** Basic file-system browsing. */
    public function fileBrowser(): static
    {
        return $this->allowedCommands(['ls', 'pwd', 'cd', 'cat', 'head', 'tail', 'find']);
    }

    /** Git operations plus basic navigation. */
    public function gitTerminal(): static
    {
        return $this->allowedCommands(['git', 'ls', 'pwd', 'cd', 'cat']);
    }

    /** Docker operations plus basic navigation. */
    public function dockerTerminal(): static
    {
        return $this->allowedCommands(['docker', 'docker-compose', 'ls', 'pwd', 'cd']);
    }

    /** npm / yarn / node operations plus basic navigation. */
    public function nodeTerminal(): static
    {
        return $this->allowedCommands(['npm', 'npx', 'node', 'yarn', 'ls', 'pwd', 'cd', 'cat']);
    }

    /** Laravel artisan + composer plus basic navigation. */
    public function artisanTerminal(): static
    {
        return $this->allowedCommands(['php', 'composer', 'ls', 'pwd', 'cd', 'cat']);
    }
}
