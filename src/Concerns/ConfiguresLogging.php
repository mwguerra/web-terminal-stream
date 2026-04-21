<?php

declare(strict_types=1);

namespace MWGuerra\WebTerminal\Concerns;

use Closure;

/**
 * Fluent configuration for terminal audit logging: connection events,
 * commands, output, identifier, and per-terminal metadata.
 *
 * Nullable fields mean "not explicitly set" — downstream code falls back
 * to the package-wide defaults in `config/web-terminal.logging.*`.
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

    protected bool|Closure|null $logCommands = null;

    protected bool|Closure|null $logOutput = null;

    protected string|Closure|null $logIdentifier = null;

    /** @var array<string, mixed>|Closure */
    protected array|Closure $logMetadata = [];

    /**
     * @param array<string, mixed>|Closure|bool|null $enabled Array/Closure config, or enabled bool when using named params
     * @param bool|Closure|null $connections
     * @param bool|Closure|null $commands
     * @param bool|Closure|null $output
     * @param string|Closure|null $identifier
     * @param array<string, mixed>|Closure|null $metadata
     */
    public function log(
        array|Closure|bool|null $enabled = true,
        bool|Closure|null $connections = null,
        bool|Closure|null $commands = null,
        bool|Closure|null $output = null,
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
                $this->logCommands = fn () => $this->evaluate($configClosure)['commands'] ?? null;
                $this->logOutput = fn () => $this->evaluate($configClosure)['output'] ?? null;
                $this->logIdentifier = fn () => $this->evaluate($configClosure)['identifier'] ?? null;
                $this->logMetadata = fn () => $this->evaluate($configClosure)['metadata'] ?? [];
            } else {
                $this->loggingEnabled = $enabled['enabled'] ?? true;
                $this->logConnections = $enabled['connections'] ?? null;
                $this->logCommands = $enabled['commands'] ?? null;
                $this->logOutput = $enabled['output'] ?? null;
                $this->logIdentifier = $enabled['identifier'] ?? null;
                $this->logMetadata = $enabled['metadata'] ?? [];
            }

            return $this;
        }

        $this->loggingEnabled = $enabled;

        if ($connections !== null) {
            $this->logConnections = $connections;
        }
        if ($commands !== null) {
            $this->logCommands = $commands;
        }
        if ($output !== null) {
            $this->logOutput = $output;
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

    public function getLogCommands(): ?bool
    {
        return $this->logCommands === null ? null : $this->evaluate($this->logCommands);
    }

    public function getLogOutput(): ?bool
    {
        return $this->logOutput === null ? null : $this->evaluate($this->logOutput);
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
     * @param array<string, mixed>|Closure $metadata
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
