<?php

declare(strict_types=1);

namespace MWGuerra\WebTerminalStream\Concerns;

use MWGuerra\WebTerminalStream\Security\ConnectionVault;

/**
 * Takes custody of any connection config found in a StreamTerminal prop set.
 *
 * ResolvesTerminalProperties already emits a handle, so schema-built panes
 * arrive safe. The containers also accept prop sets handed to them directly —
 * `@livewire('web-terminal-workspace', ['paneDefaults' => [...]])` and the
 * dashboard's `sources` — and those still carry a raw `connectionConfig`. Since
 * every container property is public Livewire state, an unvaulted prop set is
 * an SSH private key in `wire:snapshot`, so normalize at the door.
 */
trait VaultsPaneConnections
{
    /**
     * @param  array<string, mixed>  $props
     * @return array<string, mixed>
     */
    protected function vaultPaneProps(array $props): array
    {
        if (! isset($props['connectionConfig'])) {
            return $props;
        }

        $props['connectionRef'] = app(ConnectionVault::class)->put((array) $props['connectionConfig']);
        unset($props['connectionConfig']);

        return $props;
    }
}
