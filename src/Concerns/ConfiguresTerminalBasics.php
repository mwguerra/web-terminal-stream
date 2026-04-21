<?php

declare(strict_types=1);

namespace MWGuerra\WebTerminal\Concerns;

use Closure;

/**
 * Fluent configuration for terminal basics: timeout, prompt, history
 * length, and output retention.
 *
 * `workingDirectory` is intentionally NOT in this trait — it's only
 * on the Schema component today (not on TerminalBuilder) and its
 * Closure-support semantics need a separate pass before extraction.
 *
 * @internal Shared trait.
 */
trait ConfiguresTerminalBasics
{
    protected int|Closure $timeout = 10;

    protected string|Closure $prompt = '$ ';

    protected int|Closure $historyLimit = 50;

    protected int|Closure $maxOutputLines = 1000;

    public function timeout(int|Closure $seconds): static
    {
        $this->timeout = $seconds;

        return $this;
    }

    public function getTimeout(): int
    {
        return $this->evaluate($this->timeout);
    }

    public function prompt(string|Closure $prompt): static
    {
        $this->prompt = $prompt;

        return $this;
    }

    public function getPrompt(): string
    {
        return $this->evaluate($this->prompt);
    }

    public function historyLimit(int|Closure $limit): static
    {
        $this->historyLimit = $limit;

        return $this;
    }

    public function getHistoryLimit(): int
    {
        return $this->evaluate($this->historyLimit);
    }

    public function maxOutputLines(int|Closure $lines): static
    {
        $this->maxOutputLines = $lines;

        return $this;
    }

    public function getMaxOutputLines(): int
    {
        return $this->evaluate($this->maxOutputLines);
    }
}
