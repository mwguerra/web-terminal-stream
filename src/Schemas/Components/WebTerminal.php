<?php

namespace MWGuerra\WebTerminal\Schemas\Components;

use Closure;
use Filament\Schemas\Components\Livewire;
use MWGuerra\WebTerminal\Concerns\ConfiguresCommandPresets;
use MWGuerra\WebTerminal\Concerns\ConfiguresLogging;
use MWGuerra\WebTerminal\Concerns\ConfiguresPermissions;
use MWGuerra\WebTerminal\Concerns\ConfiguresScripts;
use MWGuerra\WebTerminal\Concerns\ConfiguresSessionManagement;
use MWGuerra\WebTerminal\Concerns\ConfiguresShellEnvironment;
use MWGuerra\WebTerminal\Concerns\ConfiguresStreamMode;
use MWGuerra\WebTerminal\Concerns\ConfiguresTerminalAppearance;
use MWGuerra\WebTerminal\Concerns\ConfiguresTerminalBasics;
use MWGuerra\WebTerminal\Data\ConnectionConfig;
use MWGuerra\WebTerminal\Livewire\StreamTerminal as StreamTerminalComponent;
use MWGuerra\WebTerminal\Livewire\TerminalContainer as TerminalContainerComponent;
use MWGuerra\WebTerminal\Livewire\WebTerminal as WebTerminalComponent;

/**
 * Web Terminal component for use in Filament schemas/forms.
 *
 * This component embeds the terminal into any Filament form or page using fluent API.
 * Extends Filament's built-in Livewire component for proper component isolation.
 *
 * @example
 * WebTerminal::make()
 *     ->local()
 *     ->allowedCommands(['ls', 'pwd', 'cd'])
 *     ->height('400px')
 *     ->prompt('$ ')
 */
class WebTerminal extends Livewire
{
    use ConfiguresCommandPresets;
    use ConfiguresLogging;
    use ConfiguresPermissions;
    use ConfiguresScripts;
    use ConfiguresSessionManagement;
    use ConfiguresShellEnvironment;
    use ConfiguresStreamMode;
    use ConfiguresTerminalAppearance;
    use ConfiguresTerminalBasics;

    protected array|Closure $connectionConfig = ['type' => 'local'];

    protected array|Closure $allowedCommands = [];

    protected ?string $workingDirectory = null;


    public static function make(Closure|string|null $component = null, Closure|array $data = []): static
    {
        $static = app(static::class, [
            'component' => $component ?? WebTerminalComponent::class,
            'data' => $data,
        ]);
        $static->configure();

        // Unique default key per instance so two WebTerminal::make() calls on the
        // same Filament page don't collide on Livewire's wire:key. Users who call
        // ->key('custom-id') later override this default. The random segment is
        // stable for the lifetime of a page render (make() only runs at schema
        // build time, not on every re-render).
        $static->key('web-terminal-' . \Illuminate\Support\Str::random(8));

        return $static;
    }

    /**
     * Resolve the Livewire component class based on enabled modes.
     */
    protected function resolveComponentClass(): string
    {
        $streamEnabled = $this->getStreamEnabled();
        $classicEnabled = $this->getClassicEnabled();

        if ($streamEnabled && $classicEnabled) {
            return TerminalContainerComponent::class;
        }

        if ($streamEnabled) {
            return StreamTerminalComponent::class;
        }

        return WebTerminalComponent::class;
    }

    /**
     * Get the Livewire component class to use.
     */
    public function getComponent(): string
    {
        return $this->resolveComponentClass();
    }

    /**
     * Get the properties to pass to the Livewire component.
     *
     * @return array<string, mixed>
     */
    public function getComponentProperties(): array
    {
        $streamEnabled = $this->getStreamEnabled();
        $classicEnabled = $this->getClassicEnabled();
        $config = $this->getConnectionConfig();

        // Add working directory if set
        if ($this->workingDirectory !== null) {
            $config['working_directory'] = $this->workingDirectory;
        }

        // Classic-only params (used by WebTerminal and as classicParams in container)
        $classicParams = array_filter([
            ...parent::getComponentProperties(),
            'connection' => $config,
            'allowedCommands' => $this->getAllowedCommands(),
            'allowAllCommands' => $this->getAllowAll(),
            'allowPipes' => $this->getAllowPipes(),
            'allowRedirection' => $this->getAllowRedirection(),
            'allowChaining' => $this->getAllowChaining(),
            'allowExpansion' => $this->getAllowExpansion(),
            'allowAllShellOperators' => $this->getAllowAllShellOperators(),
            'allowInteractiveMode' => $this->getAllowInteractiveMode(),
            'environment' => $this->getEnvironment(),
            'useLoginShell' => $this->getUseLoginShell(),
            'shell' => $this->getShell(),
            'timeout' => $this->getTimeout(),
            'prompt' => $this->getPrompt(),
            'historyLimit' => $this->getHistoryLimit(),
            'maxOutputLines' => $this->getMaxOutputLines(),
            'height' => $this->getHeight(),
            'startConnected' => $this->getStartConnected() || $this->getAutoConnect(),
            'autoConnect' => $this->getAutoConnect(),
            'title' => $this->getTitle(),
            'showWindowControls' => $this->getShowWindowControls(),
            'chrome' => $this->getChrome()->value,
            'loggingEnabled' => $this->getLoggingEnabled(),
            'logConnections' => $this->getLogConnections(),
            'logCommands' => $this->getLogCommands(),
            'logOutput' => $this->getLogOutput(),
            'logIdentifier' => $this->getLogIdentifier(),
            'logMetadata' => $this->getLogMetadata(),
            'disconnectOnNavigate' => $this->getDisconnectOnNavigate(),
            'inactivityTimeout' => $this->getInactivityTimeout(),
            'scripts' => $this->getScripts(),
        ], fn ($value) => $value !== null);

        // Dual-mode: TerminalContainer
        if ($streamEnabled && $classicEnabled) {
            return [
                'classicParams' => $classicParams,
                'streamParams' => [
                    'connectionConfig' => $config,
                    'streamTheme' => $this->getStreamTheme(),
                    'scripts' => $this->getScripts(),
                    'autoConnect' => $this->getAutoConnect(),
                ],
                'defaultMode' => $this->defaultMode->value,
                'height' => $this->getHeight(),
                'title' => $this->getTitle(),
                'showWindowControls' => $this->getShowWindowControls(),
            'chrome' => $this->getChrome()->value,
            ];
        }

        // Stream-only
        if ($streamEnabled) {
            return [
                'connectionConfig' => $config,
                'height' => $this->getHeight(),
                'title' => $this->getTitle(),
                'streamTheme' => $this->getStreamTheme(),
                'showWindowControls' => $this->getShowWindowControls(),
            'chrome' => $this->getChrome()->value,
                'scripts' => $this->getScripts(),
                'autoConnect' => $this->getAutoConnect(),
            ];
        }

        // Classic-only (default)
        return $classicParams;
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
     * @param  array|Closure|string  $config  Array/Closure config, or SSH host when using named params
     * @param  string|null  $username  SSH username (when using named params)
     * @param  string|null  $password  Password for password-based auth
     * @param  string|null  $key  Private key content for key-based auth
     * @param  string|null  $passphrase  Passphrase for encrypted private keys
     * @param  int  $port  SSH port (default: 22)
     */
    public function ssh(
        array|Closure|string $config,
        ?string $username = null,
        ?string $password = null,
        ?string $key = null,
        ?string $passphrase = null,
        int $port = 22
    ): static {
        // If config is array or Closure, use it directly
        if (is_array($config) || $config instanceof Closure) {
            $this->connectionConfig = $config instanceof Closure
                ? fn () => array_merge(['type' => 'ssh'], $this->evaluate($config))
                : array_merge(['type' => 'ssh'], $config);

            return $this;
        }

        // Named parameters style (config is the host string)
        $this->connectionConfig = [
            'type' => 'ssh',
            'host' => $config,
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
    // Command Configuration
    // ========================================

    /**
     * Set the allowed commands.
     */
    public function allowedCommands(array|Closure $commands): static
    {
        $this->allowedCommands = $commands;

        return $this;
    }

    /**
     * Get the allowed commands.
     */
    public function getAllowedCommands(): array
    {
        return $this->evaluate($this->allowedCommands);
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

/**
 * Backward-compatibility alias.
 *
 * @deprecated since 2.x, will be removed in 3.0.
 *             Reference the canonical class directly:
 *             `MWGuerra\WebTerminal\Schemas\Components\WebTerminal`.
 */
class_alias(WebTerminal::class, 'MWGuerra\\WebTerminal\\Schemas\\Components\\WebTerminalEmbed');
