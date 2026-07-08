<?php

declare(strict_types=1);

namespace MWGuerra\WebTerminalStream\Security;

/**
 * Verifies an SSH server's host key before authentication.
 *
 * phpseclib performs the key exchange but does NOT check the server host key
 * against anything trusted, so an outbound SSH session is open to a
 * man-in-the-middle. This class closes that gap using the same trust material
 * an operator already has: an OpenSSH `known_hosts` file, or explicit SHA256 /
 * MD5 fingerprints pinned per host in config.
 *
 * The host key string passed to {@see verify()} is exactly what phpseclib's
 * SSH2::getServerPublicHostKey() returns — "<type> <base64>[ comment]".
 */
class SshHostKeyVerifier
{
    /**
     * Throw a HostKeyVerificationException unless the presented host key is
     * trusted for the given host/port under the configured mode. A 'off' mode
     * is a no-op (the documented, insecure default).
     *
     * @param  array<string, mixed>  $config  the `security.ssh_host_key` block
     */
    public function verify(string $hostKey, string $host, int $port, array $config): void
    {
        $mode = $config['mode'] ?? 'off';

        if ($mode === 'off') {
            return;
        }

        [$sha256, $md5] = $this->fingerprints($hostKey);

        if ($sha256 === null) {
            throw new HostKeyVerificationException("Could not parse the server host key for {$host}.");
        }

        $trusted = match ($mode) {
            'fingerprints' => $this->trustedByFingerprint($config, $host, $port, $sha256, $md5),
            'known_hosts' => $this->trustedByKnownHosts($config, $host, $port, $hostKey),
            default => throw new HostKeyVerificationException("Unknown ssh_host_key mode '{$mode}'."),
        };

        if (! $trusted) {
            throw new HostKeyVerificationException(
                "Host key verification failed for {$host}:{$port} ({$sha256})."
            );
        }
    }

    /**
     * OpenSSH-format SHA256 and MD5 fingerprints of a host key string.
     *
     * @return array{0: ?string, 1: ?string} [sha256, md5] — both null if unparseable.
     */
    public function fingerprints(string $hostKey): array
    {
        $parts = preg_split('/\s+/', trim($hostKey)) ?: [];

        // "<type> <base64>" — the base64 blob is what gets fingerprinted.
        $b64 = $parts[1] ?? ($parts[0] ?? '');
        $raw = base64_decode($b64, true);

        if ($raw === false || $raw === '') {
            return [null, null];
        }

        return [
            'SHA256:'.rtrim(base64_encode(hash('sha256', $raw, true)), '='),
            'MD5:'.implode(':', str_split(md5($raw), 2)),
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function trustedByFingerprint(array $config, string $host, int $port, string $sha256, string $md5): bool
    {
        $map = $config['fingerprints'] ?? [];
        if (! is_array($map)) {
            return false;
        }

        // A "host:port" pin is more specific than a bare "host" entry.
        foreach (["{$host}:{$port}", $host] as $candidate) {
            if (! array_key_exists($candidate, $map)) {
                continue;
            }

            $expected = (array) $map[$candidate];

            foreach ($expected as $fingerprint) {
                if ($this->fingerprintEquals((string) $fingerprint, $sha256, $md5)) {
                    return true;
                }
            }

            // The host is pinned but no listed fingerprint matched: reject
            // outright rather than falling through to a less-specific entry.
            return false;
        }

        return false;
    }

    private function fingerprintEquals(string $expected, string $sha256, string $md5): bool
    {
        $expected = trim($expected);

        // Accept the fingerprint with or without its algorithm prefix.
        if (stripos($expected, 'SHA256:') === 0) {
            return hash_equals($sha256, $expected);
        }

        if (stripos($expected, 'MD5:') === 0) {
            return hash_equals(strtolower($md5), strtolower($expected));
        }

        // Bare value: compare against the base64 SHA256 body or the hex MD5 body.
        return hash_equals(substr($sha256, 7), $expected)
            || hash_equals(strtolower(str_replace(':', '', substr($md5, 4))), strtolower(str_replace(':', '', $expected)));
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function trustedByKnownHosts(array $config, string $host, int $port, string $hostKey): bool
    {
        $path = $config['known_hosts_path'] ?? null;

        if (! is_string($path) || $path === '' || ! is_file($path) || ! is_readable($path)) {
            throw new HostKeyVerificationException(
                'ssh_host_key mode is known_hosts but known_hosts_path is missing or unreadable.'
            );
        }

        $parts = preg_split('/\s+/', trim($hostKey)) ?: [];
        $presentedType = $parts[0] ?? '';
        $presentedKey = $parts[1] ?? '';

        if ($presentedKey === '') {
            return false;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $fields = preg_split('/\s+/', $line) ?: [];
            if (count($fields) < 3) {
                continue;
            }

            [$hosts, $type, $key] = [$fields[0], $fields[1], $fields[2]];

            if ($type !== $presentedType || ! hash_equals($key, $presentedKey)) {
                continue;
            }

            if ($this->knownHostsPatternMatches($hosts, $host, $port)) {
                return true;
            }
        }

        return false;
    }

    private function knownHostsPatternMatches(string $hostsField, string $host, int $port): bool
    {
        // Non-standard port entries are written as "[host]:port".
        $needles = $port === 22
            ? [$host]
            : ["[{$host}]:{$port}", $host];

        foreach (explode(',', $hostsField) as $pattern) {
            $pattern = trim($pattern);

            if (str_starts_with($pattern, '|1|')) {
                if ($this->hashedKnownHostMatches($pattern, $needles)) {
                    return true;
                }

                continue;
            }

            foreach ($needles as $needle) {
                if (strcasecmp($pattern, $needle) === 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * OpenSSH hashed known_hosts entry: |1|<base64 salt>|<base64 HMAC-SHA1>.
     *
     * @param  array<int, string>  $needles
     */
    private function hashedKnownHostMatches(string $pattern, array $needles): bool
    {
        $parts = explode('|', $pattern); // ['', '1', '<salt>', '<hash>']
        if (count($parts) !== 4) {
            return false;
        }

        $salt = base64_decode($parts[2], true);
        $expected = base64_decode($parts[3], true);
        if ($salt === false || $expected === false) {
            return false;
        }

        foreach ($needles as $needle) {
            if (hash_equals($expected, hash_hmac('sha1', $needle, $salt, true))) {
                return true;
            }
        }

        return false;
    }
}
