<?php

declare(strict_types=1);

namespace MWGuerra\WebTerminalStream\Concerns;

use Closure;
use MWGuerra\WebTerminalStream\Data\ConnectionConfig;

/**
 * Fluent connection configuration shared by the Filament schema
 * component and the Blade TerminalBuilder — one connection vocabulary
 * for every surface of the package.
 */
trait ConfiguresConnection
{
    /** @var ConnectionConfig|array<string, mixed>|Closure */
    protected ConnectionConfig|array|Closure $connection = ['type' => 'local'];

    protected string|Closure|null $workingDirectory = null;

    /**
     * Set the full connection configuration as a DTO, array, or
     * Closure (evaluated at render time).
     *
     * @param  ConnectionConfig|array<string, mixed>|Closure  $config
     */
    public function connection(ConnectionConfig|array|Closure $config): static
    {
        $this->connection = $config;

        return $this;
    }

    /**
     * Connect to a local shell.
     *
     * @param  array<string, string>  $environment
     */
    public function local(?string $workingDirectory = null, array $environment = []): static
    {
        $this->connection = array_filter([
            'type' => 'local',
            'working_directory' => $workingDirectory,
            'environment' => $environment !== [] ? $environment : null,
        ], fn (mixed $value): bool => $value !== null);

        return $this;
    }

    /**
     * Connect over SSH.
     *
     * Named parameters are the canonical form:
     *
     * @example ->ssh(host: 'example.com', username: 'deploy', privateKey: $pem)
     * @example ->ssh(host: 'example.com', username: 'deploy', password: 'secret', port: 2222)
     *
     * The first argument also accepts a full config array, or a Closure
     * resolved at render time:
     * @example ->ssh(fn () => ['host' => config('ssh.host'), ...])
     *
     * @param  array<string, mixed>|Closure|string  $host  SSH host, or a full array/Closure config
     * @param  string|null  $privateKey  Private key content for key-based auth
     * @param  string|null  $passphrase  Passphrase for encrypted private keys
     * @param  int|null  $port  SSH port (null uses the default, 22)
     * @param  array<string, string>  $environment
     */
    public function ssh(
        array|Closure|string $host,
        ?string $username = null,
        ?string $password = null,
        ?string $privateKey = null,
        ?string $passphrase = null,
        ?int $port = null,
        ?string $workingDirectory = null,
        array $environment = [],
        int $timeout = 10,
    ): static {
        // A full config array or Closure as the first argument.
        if (is_array($host) || $host instanceof Closure) {
            $config = $host;
            $this->connection = $config instanceof Closure
                ? fn (): array => array_merge(['type' => 'ssh'], $this->evaluate($config))
                : array_merge(['type' => 'ssh'], $config);

            return $this;
        }

        $this->connection = [
            'type' => 'ssh',
            'host' => $host,
            'username' => $username,
            'password' => $password,
            'private_key' => $privateKey,
            'passphrase' => $passphrase,
            'port' => $port,
            'working_directory' => $workingDirectory,
            'environment' => $environment,
            'timeout' => $timeout,
        ];

        return $this;
    }

    /**
     * Set the initial working directory. An explicit working directory
     * inside the connection config wins over this value.
     */
    public function workingDirectory(string|Closure|null $directory): static
    {
        $this->workingDirectory = $directory;

        return $this;
    }

    public function getWorkingDirectory(): ?string
    {
        return $this->evaluate($this->workingDirectory);
    }

    /**
     * Resolve the connection configuration to its wire-format array.
     *
     * @return array<string, mixed>
     */
    public function getConnectionConfig(): array
    {
        $config = $this->connection instanceof Closure
            ? $this->evaluate($this->connection)
            : $this->connection;

        if ($config instanceof ConnectionConfig) {
            $config = $config->toTransportArray();
        }

        $workingDirectory = $this->getWorkingDirectory();

        if ($workingDirectory !== null && ($config['working_directory'] ?? null) === null) {
            $config['working_directory'] = $workingDirectory;
        }

        return $config;
    }
}
