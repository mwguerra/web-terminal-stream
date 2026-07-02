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
}
