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
 * Named parameters are the canonical `log()` call shape:
 * `log(enabled: true, identifier: 'admin')`. Per-parameter Closures
 * preserve deferred evaluation.
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
     * @param  array<string, mixed>|Closure|null  $metadata
     */
    public function log(
        bool|Closure $enabled = true,
        bool|Closure|null $connections = null,
        string|Closure|null $identifier = null,
        array|Closure|null $metadata = null,
    ): static {
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
