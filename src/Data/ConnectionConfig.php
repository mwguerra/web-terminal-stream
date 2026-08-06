<?php

declare(strict_types=1);

namespace MWGuerra\WebTerminalStream\Data;

use InvalidArgumentException;
use MWGuerra\WebTerminalStream\Enums\ConnectionType;

/**
 * Data Transfer Object for connection configuration.
 *
 * This readonly class holds all configuration needed to establish
 * a connection for command execution.
 */
readonly class ConnectionConfig
{
    /**
     * @param  ConnectionType  $type  The type of connection
     * @param  string|null  $host  The host to connect to (required for SSH)
     * @param  string|null  $username  The username for authentication
     * @param  string|null  $password  The password for authentication (mutually exclusive with privateKey for SSH)
     * @param  string|null  $privateKey  Private key content for SSH authentication
     * @param  string|null  $passphrase  Passphrase for encrypted private key
     * @param  int|null  $port  The port to connect to (uses default if null)
     * @param  int  $timeout  Connection/command timeout in seconds
     * @param  string|null  $workingDirectory  Working directory for command execution
     * @param  array<string, string>  $environment  Additional environment variables
     */
    public function __construct(
        public ConnectionType $type,
        public ?string $host = null,
        public ?string $username = null,
        public ?string $password = null,
        public ?string $privateKey = null,
        public ?string $passphrase = null,
        public ?int $port = null,
        public int $timeout = 10,
        public ?string $workingDirectory = null,
        public array $environment = [],
    ) {
        $this->validate();
    }

    /**
     * Create a local connection configuration.
     */
    public static function local(
        int $timeout = 10,
        ?string $workingDirectory = null,
        array $environment = [],
    ): self {
        return new self(
            type: ConnectionType::Local,
            timeout: $timeout,
            workingDirectory: $workingDirectory,
            environment: $environment,
        );
    }

    /**
     * Create an SSH connection configuration with password authentication.
     */
    public static function sshWithPassword(
        string $host,
        string $username,
        string $password,
        ?int $port = null,
        int $timeout = 10,
        ?string $workingDirectory = null,
        array $environment = [],
    ): self {
        return new self(
            type: ConnectionType::SSH,
            host: $host,
            username: $username,
            password: $password,
            port: $port,
            timeout: $timeout,
            workingDirectory: $workingDirectory,
            environment: $environment,
        );
    }

    /**
     * Create an SSH connection configuration with key authentication.
     */
    public static function sshWithKey(
        string $host,
        string $username,
        string $privateKey,
        ?string $passphrase = null,
        ?int $port = null,
        int $timeout = 10,
        ?string $workingDirectory = null,
        array $environment = [],
    ): self {
        return new self(
            type: ConnectionType::SSH,
            host: $host,
            username: $username,
            privateKey: $privateKey,
            passphrase: $passphrase,
            port: $port,
            timeout: $timeout,
            workingDirectory: $workingDirectory,
            environment: $environment,
        );
    }

    /**
     * Create from an array of configuration values.
     *
     * @param  array<string, mixed>  $config
     */
    public static function fromArray(array $config): self
    {
        $type = $config['type'] ?? 'local';

        if (is_string($type)) {
            $type = ConnectionType::from($type);
        }

        return new self(
            type: $type,
            host: $config['host'] ?? null,
            username: $config['username'] ?? null,
            password: $config['password'] ?? null,
            privateKey: $config['private_key'] ?? null,
            passphrase: $config['passphrase'] ?? $config['private_key_passphrase'] ?? null,
            port: $config['port'] ?? null,
            timeout: $config['timeout'] ?? 10,
            workingDirectory: $config['working_directory'] ?? null,
            environment: $config['environment'] ?? [],
        );
    }

    /**
     * Get the effective port (configured or default for connection type).
     */
    public function effectivePort(): ?int
    {
        return $this->port ?? $this->type->defaultPort();
    }

    /**
     * Check if this configuration uses key-based authentication.
     */
    public function usesKeyAuthentication(): bool
    {
        return $this->privateKey !== null;
    }

    /**
     * Check if this configuration uses password authentication.
     */
    public function usesPasswordAuthentication(): bool
    {
        return $this->password !== null && $this->privateKey === null;
    }

    /**
     * Convert to array (excluding sensitive data).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'host' => $this->host,
            'username' => $this->username,
            'port' => $this->port,
            'timeout' => $this->timeout,
            'working_directory' => $this->workingDirectory,
            'uses_key_auth' => $this->usesKeyAuthentication(),
        ];
    }

    /**
     * Convert to the full wire-format array handed to the PTY bridge
     * and Livewire connection props. Includes credentials — never log
     * or display this; use toArray() for anything user-facing.
     *
     * @return array<string, mixed>
     */
    public function toTransportArray(): array
    {
        return [
            'type' => $this->type->value,
            'host' => $this->host,
            'username' => $this->username,
            'password' => $this->password,
            'private_key' => $this->privateKey,
            'passphrase' => $this->passphrase,
            'port' => $this->port,
            'timeout' => $this->timeout,
            'working_directory' => $this->workingDirectory,
            'environment' => $this->environment,
        ];
    }

    /**
     * Validate the configuration.
     *
     * @throws InvalidArgumentException
     */
    private function validate(): void
    {
        if ($this->type->requiresCredentials()) {
            if ($this->host === null || $this->host === '') {
                throw new InvalidArgumentException(
                    "Host is required for {$this->type->value} connections"
                );
            }

            if ($this->username === null || $this->username === '') {
                throw new InvalidArgumentException(
                    "Username is required for {$this->type->value} connections"
                );
            }

            if ($this->type === ConnectionType::SSH) {
                if ($this->password === null && $this->privateKey === null) {
                    throw new InvalidArgumentException(
                        'Either password or private key is required for SSH connections'
                    );
                }
            }
        }

        if ($this->timeout < 1) {
            throw new InvalidArgumentException('Timeout must be at least 1 second');
        }

        if ($this->port !== null && ($this->port < 1 || $this->port > 65535)) {
            throw new InvalidArgumentException('Port must be between 1 and 65535');
        }
    }
}
