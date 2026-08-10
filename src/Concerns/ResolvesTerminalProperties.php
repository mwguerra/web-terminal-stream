<?php

declare(strict_types=1);

namespace MWGuerra\WebTerminalStream\Concerns;

use MWGuerra\WebTerminalStream\Security\ConnectionVault;

/**
 * Maps the fluent configuration to the exact prop set accepted by
 * StreamTerminal::mount(). The single author of that contract — both
 * the Filament schema component and the Blade TerminalBuilder delegate
 * here.
 *
 * This is also the choke point where credentials stop travelling. The prop set
 * is copied around by every container — a dashboard's `$sources`, a workspace's
 * `$panes`/`$paneDefaults`/`$paneTemplate` — and each of those is a public
 * Livewire property, i.e. serialized into `wire:snapshot` and handed to the
 * browser. Emitting a vault handle here instead of the resolved config fixes
 * all of them at once, because none of them can leak what they never receive.
 */
trait ResolvesTerminalProperties
{
    /**
     * @return array<string, mixed>
     */
    protected function resolveTerminalProperties(): array
    {
        return [
            'connectionRef' => app(ConnectionVault::class)->put($this->getConnectionConfig()),
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
