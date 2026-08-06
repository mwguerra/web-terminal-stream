<?php

declare(strict_types=1);

namespace MWGuerra\WebTerminalStream\WebSocket;

class PtySessionRegistry
{
    private string $registryPath;

    public function __construct(string $storagePath)
    {
        $this->registryPath = rtrim($storagePath, '/').'/pty-sessions.json';
    }

    public function register(string $sessionId, int $pid, int $userId): void
    {
        $sessions = $this->all();
        $sessions[$sessionId] = [
            'pid' => $pid,
            'userId' => $userId,
            'createdAt' => time(),
            // Kernel start-time of the PID (Linux). Recorded so a later reap
            // can tell "our process is still alive" from "the PID was recycled
            // by an unrelated process" — killing the latter is a serious bug.
            'startTime' => self::processStartTime($pid),
        ];
        $this->save($sessions);
    }

    /**
     * Whether it is safe to signal a stale session's PID.
     *
     * Safe when we recorded no start-time (legacy entry or a platform without
     * /proc — best effort), or when the PID's current start-time still matches
     * what we recorded. Unsafe (returns false) only when we can prove the PID
     * now belongs to a different, recycled process.
     *
     * @param  array<string, mixed>  $session
     */
    public static function pidIsReapable(array $session): bool
    {
        $pid = (int) ($session['pid'] ?? 0);
        if ($pid <= 0) {
            return false;
        }

        $recorded = $session['startTime'] ?? null;
        if ($recorded === null) {
            return true;
        }

        $current = self::processStartTime($pid);
        if ($current === null) {
            // PID no longer exists (or unreadable) — nothing to kill anyway.
            return false;
        }

        return $current === $recorded;
    }

    /**
     * Kernel start-time (clock ticks since boot) from /proc/<pid>/stat field 22,
     * or null when unavailable (process gone, or not a /proc platform).
     */
    private static function processStartTime(int $pid): ?int
    {
        if ($pid <= 0) {
            return null;
        }

        $statPath = "/proc/{$pid}/stat";
        if (! is_file($statPath)) {
            return null;
        }

        $stat = @file_get_contents($statPath);
        if ($stat === false || $stat === '') {
            return null;
        }

        // The comm field (2) may contain spaces/parens; parse from the last ')'.
        $rparen = strrpos($stat, ')');
        if ($rparen === false) {
            return null;
        }

        $fields = preg_split('/\s+/', trim(substr($stat, $rparen + 1))) ?: [];

        // After comm, fields are: state(0) ppid(1) ... starttime is field 22
        // overall = index 19 in this post-comm slice (22 - 3).
        return isset($fields[19]) ? (int) $fields[19] : null;
    }

    public function unregister(string $sessionId): void
    {
        $sessions = $this->all();
        unset($sessions[$sessionId]);
        $this->save($sessions);
    }

    public function find(string $sessionId): ?array
    {
        return $this->all()[$sessionId] ?? null;
    }

    public function all(): array
    {
        if (! file_exists($this->registryPath)) {
            return [];
        }

        $content = file_get_contents($this->registryPath);
        if ($content === false || $content === '') {
            return [];
        }

        return json_decode($content, true) ?? [];
    }

    public function cleanupStale(int $maxLifetimeSeconds): array
    {
        $sessions = $this->all();
        $stale = [];
        $now = time();

        foreach ($sessions as $sessionId => $session) {
            if ($now - $session['createdAt'] > $maxLifetimeSeconds) {
                $stale[$sessionId] = $session;
                unset($sessions[$sessionId]);
            }
        }

        $this->save($sessions);

        return $stale;
    }

    private function save(array $sessions): void
    {
        $dir = dirname($this->registryPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents(
            $this->registryPath,
            json_encode($sessions, JSON_PRETTY_PRINT),
            LOCK_EX
        );
    }
}
