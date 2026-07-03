<?php

declare(strict_types=1);

namespace MWGuerra\WebTerminalStream\Concerns;

/**
 * Maps the fluent configuration to the exact prop set accepted by
 * StreamTerminal::mount(). The single author of that contract — both
 * the Filament schema component and the Blade TerminalBuilder delegate
 * here.
 */
trait ResolvesTerminalProperties
{
    /**
     * @return array<string, mixed>
     */
    protected function resolveTerminalProperties(): array
    {
        return [
            'connectionConfig' => $this->getConnectionConfig(),
            'height' => $this->getHeight(),
            'title' => $this->getTitle(),
            'theme' => $this->getTheme(),
            'fontFamily' => $this->getFontFamily(),
            'fontSize' => $this->getFontSize(),
            'chrome' => $this->getChrome()->value,
            'squareCorners' => $this->getSquareCorners(),
            'scripts' => $this->getScripts(),
            'connectionBehavior' => $this->getConnectionBehavior()->value,
            'loggingEnabled' => $this->getLoggingEnabled(),
            'logConnections' => $this->getLogConnections(),
            'logIdentifier' => $this->getLogIdentifier(),
            'logMetadata' => $this->getLogMetadata(),
        ];
    }
}
