<?php

declare(strict_types=1);

namespace MWGuerra\WebTerminalStream\Concerns;

use Closure;

/**
 * Minimal `evaluate()` implementation for classes that don't inherit one.
 *
 * The Filament Schema component already has its own `evaluate()` from
 * `Filament\Schemas\Components\Component`. This trait exists for plain
 * PHP classes (TerminalBuilder) that consume the same configuration
 * traits and need Closure-aware accessors.
 *
 * Both implementations satisfy the contract used by the
 * `Configures*` traits: "return $value; if it's a Closure, call it first."
 */
trait EvaluatesOptions
{
    protected function evaluate(mixed $value): mixed
    {
        return $value instanceof Closure ? $value() : $value;
    }
}
