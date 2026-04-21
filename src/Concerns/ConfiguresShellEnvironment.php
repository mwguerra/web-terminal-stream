<?php

declare(strict_types=1);

namespace MWGuerra\WebTerminal\Concerns;

use Closure;

/**
 * Fluent configuration for the shell used to execute commands and the
 * environment variables passed to it.
 *
 * `path()` and `inheritPath()` are convenience methods that write to the
 * PATH key of `$environment` — useful when commands live in non-standard
 * locations (NVM, homebrew, rbenv, pyenv).
 *
 * @internal Shared trait.
 */
trait ConfiguresShellEnvironment
{
    /** @var array<string, string>|Closure */
    protected array|Closure $environment = [];

    protected bool|Closure $useLoginShell = false;

    protected string|Closure $shell = '/bin/bash';

    /**
     * @param array<string, string>|Closure $environment
     */
    public function environment(array|Closure $environment): static
    {
        $this->environment = $environment;

        return $this;
    }

    /**
     * @return array<string, string>
     */
    public function getEnvironment(): array
    {
        return $this->evaluate($this->environment);
    }

    /**
     * Set PATH inside the environment variables map.
     */
    public function path(string|Closure $path): static
    {
        $env = $this->getEnvironment();
        $env['PATH'] = $this->evaluate($path);
        $this->environment = $env;

        return $this;
    }

    /**
     * Inherit PATH from the current server environment.
     *
     * This may not include user-shell-specific paths (NVM, rbenv, etc.) that
     * are only set by `.bashrc` / `.zshrc` — use `loginShell()` for that.
     */
    public function inheritPath(): static
    {
        $env = $this->getEnvironment();
        $env['PATH'] = getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin';
        $this->environment = $env;

        return $this;
    }

    /**
     * Run commands through a login shell (`bash -l -i -c ...`) so
     * `.bashrc` / `.bash_profile` are sourced and the user's full
     * environment is available.
     */
    public function loginShell(bool|Closure $useLoginShell = true): static
    {
        $this->useLoginShell = $useLoginShell;

        return $this;
    }

    public function getUseLoginShell(): bool
    {
        return $this->evaluate($this->useLoginShell);
    }

    public function shell(string|Closure $shell): static
    {
        $this->shell = $shell;

        return $this;
    }

    public function getShell(): string
    {
        return $this->evaluate($this->shell);
    }
}
