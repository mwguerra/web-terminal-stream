<?php

namespace MWGuerra\WebTerminalStream\Schemas\Components;

use Closure;
use Filament\Schemas\Components\Livewire;
use Illuminate\Support\Str;
use MWGuerra\WebTerminalStream\Concerns\ConfiguresLogging;
use MWGuerra\WebTerminalStream\Concerns\ConfiguresScripts;
use MWGuerra\WebTerminalStream\Concerns\ConfiguresStreamMode;
use MWGuerra\WebTerminalStream\Concerns\ConfiguresTerminalAppearance;
use MWGuerra\WebTerminalStream\Data\ConnectionConfig;
use MWGuerra\WebTerminalStream\Livewire\StreamTerminal as StreamTerminalComponent;

/**
 * Web Terminal component for use in Filament schemas/forms.
 *
 * This component embeds the Stream terminal (full interactive PTY over
 * WebSocket) into any Filament form or page using a fluent API. Extends
 * Filament's built-in Livewire component for proper component isolation.
 *
 * @example
 * WebTerminalStream::make()
 *     ->local()
 *     ->height('400px')
 *     ->title('Server Console')
 */
class WebTerminalStream extends Livewire
{
    use ConfiguresLogging;
    use ConfiguresScripts;
    use ConfiguresStreamMode;
    use ConfiguresTerminalAppearance;

    protected array|Closure $connectionConfig = ['type' => 'local'];

    protected ?string $workingDirectory = null;

    public static function make(Closure|string|null $component = null, Closure|array $data = []): static
    {
        $static = app(static::class, [
            'component' => $component ?? StreamTerminalComponent::class,
            'data' => $data,
        ]);
        $static->configure();

        // Unique default key per instance so two WebTerminalStream::make() calls on the
        // same Filament page don't collide on Livewire's wire:key. Users who call
        // ->key('custom-id') later override this default. The random segment is
        // stable for the lifetime of a page render (make() only runs at schema
        // build time, not on every re-render).
        $static->key('web-terminal-stream-'.Str::random(8));

        return $static;
    }

    /**
     * Get the Livewire component class to use.
     */
    public function getComponent(): string
    {
        return StreamTerminalComponent::class;
    }

    /**
     * Get the properties to pass to the Livewire component.
     *
     * @return array<string, mixed>
     */
    public function getComponentProperties(): array
    {
        $config = $this->getConnectionConfig();

        // Add working directory if set
        if ($this->workingDirectory !== null) {
            $config['working_directory'] = $this->workingDirectory;
        }

        return [
            'connectionConfig' => $config,
            'height' => $this->getHeight(),
            'title' => $this->getTitle(),
            'streamTheme' => $this->getStreamTheme(),
            'showWindowControls' => $this->getShowWindowControls(),
            'chrome' => $this->getChrome()->value,
            'squareCorners' => $this->getSquareCorners(),
            'scripts' => $this->getScripts(),
            'autoConnect' => $this->getAutoConnect(),
            'connectionBehavior' => $this->getEffectiveConnectionBehavior()->value,
            'loggingEnabled' => $this->getLoggingEnabled(),
            'logConnections' => $this->getLogConnections(),
            'logIdentifier' => $this->getLogIdentifier(),
            'logMetadata' => $this->getLogMetadata(),
        ];
    }

    // ========================================
    // Connection Configuration
    // ========================================

    /**
     * Set the connection configuration.
     */
    public function connection(array|Closure|ConnectionConfig $config): static
    {
        if ($config instanceof ConnectionConfig) {
            $this->connectionConfig = [
                'type' => $config->type->value,
                'host' => $config->host,
                'username' => $config->username,
                'password' => $config->password,
                'private_key' => $config->privateKey,
                'passphrase' => $config->passphrase,
                'port' => $config->port,
                'timeout' => $config->timeout,
                'working_directory' => $config->workingDirectory,
                'environment' => $config->environment,
            ];
        } else {
            $this->connectionConfig = $config;
        }

        return $this;
    }

    /**
     * Configure for local connection.
     */
    public function local(): static
    {
        $this->connectionConfig = ['type' => 'local'];

        return $this;
    }

    /**
     * Configure for SSH connection.
     *
     * Supports both password and key-based authentication:
     * - Password auth: provide `password` parameter
     * - Key auth: provide `key` parameter with the private key content
     *
     * Can be called with named parameters or an array/Closure:
     *
     * @example Named parameters:
     * ->ssh(host: 'example.com', username: 'user', password: 'pass')
     * @example Array configuration:
     * ->ssh(['host' => 'example.com', 'username' => 'user', 'password' => 'pass'])
     * @example Closure (evaluated at render time):
     * ->ssh(fn () => [
     *     'host' => config('ssh.host'),
     *     'username' => config('ssh.username'),
     *     'private_key' => Storage::get('ssh/key'),
     * ])
     *
     * @param  array|Closure|string  $host  SSH host (when using named params), or a full array/Closure config
     * @param  string|null  $username  SSH username (when using named params)
     * @param  string|null  $password  Password for password-based auth
     * @param  string|null  $key  Private key content for key-based auth
     * @param  string|null  $passphrase  Passphrase for encrypted private keys
     * @param  int  $port  SSH port (default: 22)
     */
    public function ssh(
        array|Closure|string $host,
        ?string $username = null,
        ?string $password = null,
        ?string $key = null,
        ?string $passphrase = null,
        int $port = 22
    ): static {
        // If the first argument is an array or Closure, it is the full config
        if (is_array($host) || $host instanceof Closure) {
            $config = $host;
            $this->connectionConfig = $config instanceof Closure
                ? fn () => array_merge(['type' => 'ssh'], $this->evaluate($config))
                : array_merge(['type' => 'ssh'], $config);

            return $this;
        }

        // Named parameters style
        $this->connectionConfig = [
            'type' => 'ssh',
            'host' => $host,
            'username' => $username,
            'password' => $password,
            'private_key' => $key,
            'passphrase' => $passphrase,
            'port' => $port,
        ];

        return $this;
    }

    /**
     * Get the connection configuration.
     */
    public function getConnectionConfig(): array
    {
        return $this->evaluate($this->connectionConfig);
    }

    // ========================================
    // Terminal Settings
    // ========================================

    /**
     * Set the initial working directory.
     */
    public function workingDirectory(?string $directory): static
    {
        $this->workingDirectory = $directory;

        return $this;
    }

    /**
     * Get the working directory.
     */
    public function getWorkingDirectory(): ?string
    {
        return $this->workingDirectory;
    }
}
