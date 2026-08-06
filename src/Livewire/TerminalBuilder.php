<?php

declare(strict_types=1);

namespace MWGuerra\WebTerminalStream\Livewire;

use Illuminate\Support\HtmlString;
use Livewire\Livewire;
use MWGuerra\WebTerminalStream\Concerns\ConfiguresAppearance;
use MWGuerra\WebTerminalStream\Concerns\ConfiguresConnection;
use MWGuerra\WebTerminalStream\Concerns\ConfiguresConnectionLifecycle;
use MWGuerra\WebTerminalStream\Concerns\ConfiguresLogging;
use MWGuerra\WebTerminalStream\Concerns\ConfiguresScripts;
use MWGuerra\WebTerminalStream\Concerns\EvaluatesOptions;
use MWGuerra\WebTerminalStream\Concerns\ResolvesTerminalProperties;

/**
 * Fluent builder for the Stream terminal component.
 *
 * Provides a clean, chainable API for configuring the terminal
 * before rendering it in a Blade view.
 */
class TerminalBuilder
{
    use ConfiguresAppearance;
    use ConfiguresConnection;
    use ConfiguresConnectionLifecycle;
    use ConfiguresLogging;
    use ConfiguresScripts;
    use EvaluatesOptions;
    use ResolvesTerminalProperties;

    protected ?string $key = null;

    // ========================================
    // UI Configuration
    // ========================================

    public function key(string $key): static
    {
        $this->key = $key;

        return $this;
    }

    // ========================================
    // Build & Render
    // ========================================

    /** @return array<string, mixed> */
    public function getParameters(): array
    {
        return $this->resolveTerminalProperties();
    }

    public function render(): HtmlString
    {
        $params = $this->getParameters();

        if ($this->key !== null) {
            return new HtmlString(
                Livewire::mount('web-terminal-stream', $params, $this->key)
            );
        }

        return new HtmlString(
            Livewire::mount('web-terminal-stream', $params)
        );
    }
}
