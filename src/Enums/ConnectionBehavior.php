<?php

declare(strict_types=1);

namespace MWGuerra\WebTerminalStream\Enums;

/**
 * How the terminal connects on mount and what UI the user sees.
 *
 * Replaces the confusing `startConnected()` + `autoConnect()` pair where
 * setting one implied the other in non-obvious ways.
 */
enum ConnectionBehavior: string
{
    /** User connects explicitly. Default. */
    case Manual = 'manual';

    /** Auto-connects on mount. Disconnect button visible; user can end the session. */
    case Auto = 'auto';

    /**
     * Auto-connects on mount and hides the connect/disconnect button entirely.
     * Session persists for the view's lifetime. Matches the Stream terminal's
     * current always-auto-connect behavior.
     */
    case Always = 'always';

    /**
     * Map to the underlying start/auto flags the Livewire component consumes.
     *
     * @return array{startConnected: bool, autoConnect: bool}
     */
    public function toFlags(): array
    {
        return match ($this) {
            self::Manual => ['startConnected' => false, 'autoConnect' => false],
            self::Auto => ['startConnected' => true, 'autoConnect' => false],
            self::Always => ['startConnected' => true, 'autoConnect' => true],
        };
    }
}
