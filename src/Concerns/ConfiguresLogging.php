<?php

declare(strict_types=1);

namespace MWGuerra\WebTerminalStream\Concerns;

use Closure;

/**
 * Fluent configuration for terminal audit logging: connection events,
 * identifier, and per-terminal metadata.
 *
 * Stream mode is a raw PTY byte-pipe, so there is no command-level
 * logging — only connection lifecycle events are recorded.
 *
 * Nullable fields mean "not explicitly set" — downstream code falls back
 * to the package-wide defaults in `config/web-terminal-stream.logging.*`.
 *
 * The `log()` method accepts three call shapes:
 *   - Named parameters: `log(enabled: true, identifier: 'admin')`
 *   - Array config:     `log(['enabled' => true, 'metadata' => [...]])`
 *   - Closure:          `log(fn () => [...])` — deferred evaluation
 *
 * @internal Shared trait.
 */
trait ConfiguresLogging
{
    protected bool|Closure|null $loggingEnabled = null;

    protected bool|Closure|null $logConnections = null;

    protected string|Closure|null $logIdentifier = null;

    /** @var array<string, mixed>|Closure */
    protected array|Closure $logMetadata = [];

    /**
     * @param  array<string, mixed>|Closure|bool|null  $enabled  Array/Closure config, or enabled bool when using named params
     * @param  array<string, mixed>|Closure|null  $metadata
     */
    public function log(
        array|Closure|bool|null $enabled = true,
        bool|Closure|null $connections = null,
        string|Closure|null $identifier = null,
        array|Closure|null $metadata = null,
    ): static {
        if (is_array($enabled) || $enabled instanceof Closure) {
            if ($enabled instanceof Closure) {
                // Defer each individual value so the Closure is evaluated
                // only when the consumer reads the field.
                $configClosure = $enabled;
                $this->loggingEnabled = fn () => $this->evaluate($configClosure)['enabled'] ?? true;
                $this->logConnections = fn () => $this->evaluate($configClosure)['connections'] ?? null;
                $this->logIdentifier = fn () => $this->evaluate($configClosure)['identifier'] ?? null;
                $this->logMetadata = fn () => $this->evaluate($configClosure)['metadata'] ?? [];
            } else {
                $this->loggingEnabled = $enabled['enabled'] ?? true;
                $this->logConnections = $enabled['connections'] ?? null;
                $this->logIdentifier = $enabled['identifier'] ?? null;
                $this->logMetadata = $enabled['metadata'] ?? [];
            }

            return $this;
        }

        $this->loggingEnabled = $enabled;

        if ($connections !== null) {
            $this->logConnections = $connections;
        }
        if ($identifier !== null) {
            $this->logIdentifier = $identifier;
        }
        if ($metadata !== null) {
            $this->logMetadata = $metadata;
        }

        return $this;
    }

    public function getLoggingEnabled(): ?bool
    {
        return $this->loggingEnabled === null ? null : $this->evaluate($this->loggingEnabled);
    }

    public function getLogConnections(): ?bool
    {
        return $this->logConnections === null ? null : $this->evaluate($this->logConnections);
    }

    public function getLogIdentifier(): ?string
    {
        return $this->logIdentifier === null ? null : $this->evaluate($this->logIdentifier);
    }

    /**
     * Set custom metadata attached to every log entry for this terminal.
     *
     * Merged with any existing metadata on the entry — useful for tagging
     * logs with server name, environment, project ID, etc.
     *
     * @param  array<string, mixed>|Closure  $metadata
     */
    public function logMetadata(array|Closure $metadata): static
    {
        $this->logMetadata = $metadata;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getLogMetadata(): array
    {
        return $this->evaluate($this->logMetadata);
    }
}
