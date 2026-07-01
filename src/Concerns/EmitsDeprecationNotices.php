<?php

declare(strict_types=1);

namespace MWGuerra\WebTerminalStream\Concerns;

/**
 * Centralized `E_USER_DEPRECATED` emitter gated by config.
 *
 * Deprecated fluent-API methods call `$this->emitDeprecationNotice()` so
 * users can opt-in to seeing their usage (flip
 * `web-terminal-stream.deprecations.emit_notices` to `true`) without polluting
 * logs for everyone by default.
 *
 * @internal
 */
trait EmitsDeprecationNotices
{
    /**
     * Emit a deprecation notice when the config flag is on.
     *
     * Never throws. Never breaks user code. The goal is discoverability,
     * not enforcement.
     */
    protected function emitDeprecationNotice(string $deprecated, string $replacement, string $removedIn = '3.0'): void
    {
        if (! function_exists('config') || ! config('web-terminal-stream.deprecations.emit_notices', false)) {
            return;
        }

        @trigger_error(
            sprintf(
                'web-terminal-stream: %s is deprecated and will be removed in %s. Use %s instead.',
                $deprecated,
                $removedIn,
                $replacement,
            ),
            E_USER_DEPRECATED,
        );
    }
}
