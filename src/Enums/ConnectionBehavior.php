<?php

declare(strict_types=1);

namespace MWGuerra\WebTerminalStream\Enums;

/**
 * How the terminal connects on mount and what UI the user sees.
 */
enum ConnectionBehavior: string
{
    /** User connects explicitly via the connect button. */
    case Manual = 'manual';

    /** Auto-connects on mount. Disconnect button visible; user can end the session. */
    case Auto = 'auto';

    /**
     * Auto-connects on mount with no connect/disconnect UI. The session
     * persists for the view's lifetime. Default.
     */
    case Always = 'always';
}
