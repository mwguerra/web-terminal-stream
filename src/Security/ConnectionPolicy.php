<?php

declare(strict_types=1);

namespace MWGuerra\WebTerminalStream\Security;

use MWGuerra\WebTerminalStream\Enums\ConnectionType;

/**
 * Decides whether a resolved connection config is allowed to open a PTY.
 *
 * This is the server-side boundary on WHAT a terminal may connect to,
 * independent of WHO may open one (the useStreamTerminal Gate). It reads
 * `web-terminal-stream.security.*` and is enforced on every token-issuance
 * path and re-checked on the WebSocket server before a bridge starts, so a
 * token minted for a disallowed target is still refused.
 */
class ConnectionPolicy
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function allows(array $config): bool
    {
        return $this->deniedReason($config) === null;
    }

    /**
     * The reason the config is disallowed, or null when it is allowed.
     *
     * @param  array<string, mixed>  $config
     */
    public function deniedReason(array $config): ?string
    {
        $type = $config['type'] ?? ConnectionType::Local->value;

        if ($type === ConnectionType::Local->value) {
            return $this->config('allow_local', true)
                ? null
                : 'Local terminals are disabled by configuration.';
        }

        if ($type === ConnectionType::SSH->value) {
            $allowed = $this->config('ssh_allowed_hosts', []);

            // Empty allow-list = any host permitted (documented, not recommended).
            if (! is_array($allowed) || $allowed === []) {
                return null;
            }

            $host = $config['host'] ?? null;
            $port = $config['port'] ?? null;

            foreach ($allowed as $entry) {
                if ($this->hostMatches((string) $entry, $host, $port)) {
                    return null;
                }
            }

            return sprintf("SSH host '%s' is not in the configured allow-list.", $host ?? '(none)');
        }

        return sprintf("Unknown connection type '%s'.", $type);
    }

    private function hostMatches(string $entry, ?string $host, int|string|null $port): bool
    {
        if ($host === null) {
            return false;
        }

        // "host:port" pins the port; bare "host" matches any port.
        if (str_contains($entry, ':')) {
            [$entryHost, $entryPort] = explode(':', $entry, 2);

            return $entryHost === $host && $entryPort === (string) ($port ?? '');
        }

        return $entry === $host;
    }

    private function config(string $key, mixed $default): mixed
    {
        return config("web-terminal-stream.security.{$key}", $default);
    }
}
