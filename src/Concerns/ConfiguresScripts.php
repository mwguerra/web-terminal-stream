<?php

declare(strict_types=1);

namespace MWGuerra\WebTerminalStream\Concerns;

use Closure;
use MWGuerra\WebTerminalStream\Data\Script;

/**
 * Fluent configuration for the list of scripts exposed in the terminal
 * dropdown. Accepts a Closure for dynamic resolution (e.g. per-user).
 *
 * `getScripts()` always returns an array of normalized array<string, mixed>
 * entries regardless of whether the caller passed `Script` DTOs or raw
 * arrays — downstream consumers can assume one shape.
 *
 * @internal Shared trait.
 */
trait ConfiguresScripts
{
    /** @var array<int, Script|array<string, mixed>>|Closure */
    protected array|Closure $scripts = [];

    /**
     * @param  array<int, Script|array<string, mixed>>|Closure  $scripts
     */
    public function scripts(array|Closure $scripts): static
    {
        $this->scripts = $scripts;

        return $this;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getScripts(): array
    {
        $scripts = $this->evaluate($this->scripts);

        if (! is_array($scripts)) {
            return [];
        }

        return array_values(array_map(function ($script) {
            if ($script instanceof Script) {
                return $script->toArray();
            }

            return Script::fromArray($script)->toArray();
        }, $scripts));
    }
}
