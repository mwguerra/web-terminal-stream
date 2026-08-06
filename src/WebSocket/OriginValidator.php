<?php

declare(strict_types=1);

namespace MWGuerra\WebTerminalStream\WebSocket;

/**
 * Validates a WebSocket handshake's `Origin` header against the
 * `stream.allowed_origins` config allow-list.
 *
 * Matching is done on normalized origins — scheme + case-insensitive host +
 * port (default ports filled in per scheme), no path — and requires an exact
 * match. A literal `'*'` entry disables the check entirely (documented escape
 * hatch for reverse-proxy setups that strip or rewrite the Origin header).
 *
 * A missing Origin header is always allowed: browsers always send Origin on
 * WebSocket upgrades, so absence means a non-browser client (CLI tooling,
 * tests) and the encrypted single-use token remains the auth gate.
 */
class OriginValidator
{
    private const DEFAULT_PORTS = [
        'http' => 80,
        'ws' => 80,
        'https' => 443,
        'wss' => 443,
    ];

    /** @var list<string> */
    private array $allowedOrigins;

    /**
     * @param  array<int, mixed>  $allowedOrigins
     */
    public function __construct(array $allowedOrigins)
    {
        $this->allowedOrigins = array_values(array_filter(
            $allowedOrigins,
            fn ($origin) => is_string($origin) && $origin !== '',
        ));
    }

    public function allows(?string $origin): bool
    {
        // Non-browser client (no Origin header) — the token is the auth gate.
        if ($origin === null || $origin === '') {
            return true;
        }

        // No allow-list configured (e.g. a stale published config that predates
        // this key). The check is effectively disabled rather than locking every
        // browser out of the terminal.
        if ($this->allowedOrigins === []) {
            return true;
        }

        if (in_array('*', $this->allowedOrigins, true)) {
            return true;
        }

        $normalized = $this->normalize($origin);

        // Malformed Origin values never match anything.
        if ($normalized === null) {
            return false;
        }

        foreach ($this->allowedOrigins as $allowed) {
            if ($this->normalize($allowed) === $normalized) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalize an origin string to `scheme://host:port` (lowercased,
     * default port filled in). Returns null when the value cannot be
     * parsed into at least a scheme and host.
     */
    private function normalize(string $origin): ?string
    {
        $parts = parse_url(trim($origin));

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $port = $parts['port'] ?? self::DEFAULT_PORTS[$scheme] ?? null;

        return $scheme.'://'.$host.($port !== null ? ':'.$port : '');
    }
}
