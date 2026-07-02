<?php

declare(strict_types=1);

namespace MWGuerra\WebTerminalStream\Livewire;

use Illuminate\Support\HtmlString;
use Livewire\Livewire;
use MWGuerra\WebTerminalStream\Concerns\ConfiguresConnection;
use MWGuerra\WebTerminalStream\Concerns\ConfiguresLogging;
use MWGuerra\WebTerminalStream\Concerns\ConfiguresScripts;
use MWGuerra\WebTerminalStream\Concerns\ConfiguresStreamMode;
use MWGuerra\WebTerminalStream\Concerns\ConfiguresTerminalAppearance;
use MWGuerra\WebTerminalStream\Concerns\EvaluatesOptions;

/**
 * Fluent builder for the Stream terminal component.
 *
 * Provides a clean, chainable API for configuring the terminal
 * before rendering it in a Blade view.
 */
class TerminalBuilder
{
    use ConfiguresConnection;
    use ConfiguresLogging;
    use ConfiguresScripts;
    use ConfiguresStreamMode;
    use ConfiguresTerminalAppearance;
    use EvaluatesOptions;

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
        return [
            'connectionConfig' => $this->getConnectionConfig(),
            'height' => $this->getHeight(),
            'title' => $this->getTitle(),
            'streamTheme' => $this->getStreamTheme(),
            'showWindowControls' => $this->getShowWindowControls(),
            'chrome' => $this->getChrome()->value,
            'squareCorners' => $this->getSquareCorners(),
            'scripts' => $this->getScripts(),
            'autoConnect' => $this->getAutoConnect(),
            'connectionBehavior' => $this->getEffectiveConnectionBehavior()->value,
            'loggingEnabled' => $this->getLoggingEnabled(),
            'logConnections' => $this->getLogConnections(),
            'logIdentifier' => $this->getLogIdentifier(),
            'logMetadata' => $this->getLogMetadata(),
        ];
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
