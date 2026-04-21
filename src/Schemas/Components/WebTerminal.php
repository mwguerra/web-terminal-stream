<?php

namespace MWGuerra\WebTerminal\Schemas\Components;

use Closure;
use Filament\Schemas\Components\Livewire;
use MWGuerra\WebTerminal\Concerns\ConfiguresCommandPresets;
use MWGuerra\WebTerminal\Concerns\ConfiguresScripts;
use MWGuerra\WebTerminal\Concerns\ConfiguresSessionManagement;
use MWGuerra\WebTerminal\Concerns\ConfiguresShellEnvironment;
use MWGuerra\WebTerminal\Concerns\ConfiguresStreamMode;
use MWGuerra\WebTerminal\Concerns\ConfiguresTerminalAppearance;
use MWGuerra\WebTerminal\Concerns\ConfiguresTerminalBasics;
use MWGuerra\WebTerminal\Data\ConnectionConfig;
use MWGuerra\WebTerminal\Data\Script;
use MWGuerra\WebTerminal\Enums\TerminalMode;
use MWGuerra\WebTerminal\Enums\TerminalPermission;
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
    use ConfiguresScripts;
    use ConfiguresSessionManagement;
    use ConfiguresShellEnvironment;
    use ConfiguresStreamMode;
    use ConfiguresTerminalAppearance;
    use ConfiguresTerminalBasics;

    protected array|Closure $connectionConfig = ['type' => 'local'];

    protected array|Closure $allowedCommands = [];

    protected ?string $workingDirectory = null;

    protected bool|Closure $allowAllCommands = false;

    protected bool|Closure $allowInteractiveMode = false;

    protected bool|Closure $allowPipes = false;

    protected bool|Closure $allowRedirection = false;

    protected bool|Closure $allowChaining = false;

    protected bool|Closure $allowExpansion = false;

    protected bool|Closure $allowAllShellOperators = false;

    // Logging configuration
    protected bool|Closure|null $loggingEnabled = null;

    protected bool|Closure|null $logConnections = null;

    protected bool|Closure|null $logCommands = null;

    protected bool|Closure|null $logOutput = null;

    protected string|Closure|null $logIdentifier = null;

    protected array|Closure $logMetadata = [];


    public static function make(Closure|string|null $component = null, Closure|array $data = []): static
    {
        $static = app(static::class, [
            'component' => $component ?? WebTerminalComponent::class,
            'data' => $data,
        ]);
        $static->configure();
        $static->key('web-terminal');

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

    /**
     * Allow all commands (bypass whitelist).
     *
     * WARNING: This allows any command to be executed. Use with caution.
     * Only use this for development/testing purposes or in trusted environments.
     */
    public function allowAllCommands(bool|Closure $allowAll = true): static
    {
        $this->allowAllCommands = $allowAll;

        return $this;
    }

    public function getAllowAll(): bool
    {
        return $this->evaluate($this->allowAllCommands);
    }

    /**
     * Enable interactive execution mode (PTY/tmux).
     *
     * When enabled, whitelisted commands run through the interactive path
     * with streaming output and stdin support. The command whitelist is
     * still enforced — this only changes HOW commands are executed.
     */
    public function allowInteractiveMode(bool|Closure $allow = true): static
    {
        $this->allowInteractiveMode = $allow;

        return $this;
    }

    public function getAllowInteractiveMode(): bool
    {
        return $this->evaluate($this->allowInteractiveMode);
    }

    /**
     * Set permissions using TerminalPermission enum values.
     *
     * Provides a clean, declarative way to configure terminal capabilities.
     *
     * @param  array<TerminalPermission>  $permissions
     */
    public function allow(array $permissions): static
    {
        $flags = TerminalPermission::resolveManyFlags($permissions);

        if ($flags['allowAllCommands'] ?? false) {
            $this->allowAllCommands = true;
        }
        if ($flags['allowPipes'] ?? false) {
            $this->allowPipes = true;
        }
        if ($flags['allowRedirection'] ?? false) {
            $this->allowRedirection = true;
        }
        if ($flags['allowChaining'] ?? false) {
            $this->allowChaining = true;
        }
        if ($flags['allowExpansion'] ?? false) {
            $this->allowExpansion = true;
        }
        if ($flags['allowAllShellOperators'] ?? false) {
            $this->allowAllShellOperators = true;
        }
        if ($flags['allowInteractiveMode'] ?? false) {
            $this->allowInteractiveMode = true;
        }

        return $this;
    }

    // ========================================
    // Shell Operator Controls
    // ========================================

    /**
     * Allow pipe operators (|) in commands.
     *
     * Enables piping output between commands (e.g., `ls | grep foo`).
     * Risk: Low - pipes pass data between processes.
     */
    public function allowPipes(bool|Closure $allow = true): static
    {
        $this->allowPipes = $allow;

        return $this;
    }

    public function getAllowPipes(): bool
    {
        return $this->evaluate($this->allowPipes);
    }

    /**
     * Allow redirection operators (>, <, >>, <<) in commands.
     *
     * Enables file redirection (e.g., `echo test > file.txt`).
     * Risk: Medium - can overwrite or read files.
     */
    public function allowRedirection(bool|Closure $allow = true): static
    {
        $this->allowRedirection = $allow;

        return $this;
    }

    public function getAllowRedirection(): bool
    {
        return $this->evaluate($this->allowRedirection);
    }

    /**
     * Allow chaining operators (;, &&, ||, &) in commands.
     *
     * Enables running multiple commands (e.g., `ls && pwd`).
     * Risk: Medium - allows executing multiple commands sequentially.
     */
    public function allowChaining(bool|Closure $allow = true): static
    {
        $this->allowChaining = $allow;

        return $this;
    }

    public function getAllowChaining(): bool
    {
        return $this->evaluate($this->allowChaining);
    }

    /**
     * Allow expansion operators ($, `, $(), ${}) in commands.
     *
     * Enables variable and command substitution (e.g., `echo $HOME`).
     * Risk: High - allows arbitrary command execution via substitution.
     */
    public function allowExpansion(bool|Closure $allow = true): static
    {
        $this->allowExpansion = $allow;

        return $this;
    }

    public function getAllowExpansion(): bool
    {
        return $this->evaluate($this->allowExpansion);
    }

    /**
     * Allow all shell operators (pipes, redirection, chaining, expansion).
     *
     * WARNING: This disables all operator filtering. Use with caution.
     * Only use in trusted environments where users need full shell access.
     */
    public function allowAllShellOperators(bool|Closure $allow = true): static
    {
        $this->allowAllShellOperators = $allow;
        $this->allowPipes = $allow;
        $this->allowRedirection = $allow;
        $this->allowChaining = $allow;
        $this->allowExpansion = $allow;

        return $this;
    }

    public function getAllowAllShellOperators(): bool
    {
        return $this->evaluate($this->allowAllShellOperators);
    }

    // ========================================
    // Environment Configuration
    // ========================================

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

    // ========================================
    // Logging Configuration
    // ========================================

    /**
     * Configure logging for this terminal.
     *
     * All parameters have sensible defaults from config. When not specified,
     * values from config/web-terminal.php are used.
     *
     * Can be called with named parameters or an array/Closure:
     *
     * @example Named parameters:
     * ->log(enabled: true, commands: true, identifier: 'my-terminal')
     * @example Array configuration:
     * ->log([
     *     'enabled' => true,
     *     'connections' => true,
     *     'commands' => true,
     *     'identifier' => 'my-terminal',
     *     'metadata' => ['context' => 'admin'],
     * ])
     * @example Closure (evaluated at render time):
     * ->log(fn () => [
     *     'enabled' => true,
     *     'identifier' => 'terminal-' . auth()->id(),
     *     'metadata' => ['user_id' => auth()->id()],
     * ])
     *
     * @param  array|Closure|bool|null  $enabled  Array/Closure config, or enabled boolean when using named params
     * @param  bool|Closure|null  $connections  Log connection/disconnection events
     * @param  bool|Closure|null  $commands  Log command executions
     * @param  bool|Closure|null  $output  Log command output (can be verbose)
     * @param  string|Closure|null  $identifier  Custom identifier for filtering logs
     * @param  array|Closure|null  $metadata  Custom metadata for all log entries
     */
    public function log(
        array|Closure|bool|null $enabled = true,
        bool|Closure|null $connections = null,
        bool|Closure|null $commands = null,
        bool|Closure|null $output = null,
        string|Closure|null $identifier = null,
        array|Closure|null $metadata = null,
    ): static {
        // If enabled is array or Closure, extract values from it
        if (is_array($enabled) || $enabled instanceof Closure) {
            if ($enabled instanceof Closure) {
                // Store closure to be evaluated later - wrap individual values
                $configClosure = $enabled;
                $this->loggingEnabled = fn () => $this->evaluate($configClosure)['enabled'] ?? true;
                $this->logConnections = fn () => $this->evaluate($configClosure)['connections'] ?? null;
                $this->logCommands = fn () => $this->evaluate($configClosure)['commands'] ?? null;
                $this->logOutput = fn () => $this->evaluate($configClosure)['output'] ?? null;
                $this->logIdentifier = fn () => $this->evaluate($configClosure)['identifier'] ?? null;
                $this->logMetadata = fn () => $this->evaluate($configClosure)['metadata'] ?? [];
            } else {
                // Array config
                $this->loggingEnabled = $enabled['enabled'] ?? true;
                $this->logConnections = $enabled['connections'] ?? null;
                $this->logCommands = $enabled['commands'] ?? null;
                $this->logOutput = $enabled['output'] ?? null;
                $this->logIdentifier = $enabled['identifier'] ?? null;
                $this->logMetadata = $enabled['metadata'] ?? [];
            }

            return $this;
        }

        // Named parameters style
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

    /**
     * Get whether logging is enabled.
     *
     * Returns null if not explicitly set (uses config default).
     */
    public function getLoggingEnabled(): ?bool
    {
        $value = $this->loggingEnabled;

        if ($value === null) {
            return null;
        }

        return $this->evaluate($value);
    }

    /**
     * Get whether to log connections.
     *
     * Returns null if not explicitly set (uses config default).
     */
    public function getLogConnections(): ?bool
    {
        $value = $this->logConnections;

        if ($value === null) {
            return null;
        }

        return $this->evaluate($value);
    }

    /**
     * Get whether to log commands.
     *
     * Returns null if not explicitly set (uses config default).
     */
    public function getLogCommands(): ?bool
    {
        $value = $this->logCommands;

        if ($value === null) {
            return null;
        }

        return $this->evaluate($value);
    }

    /**
     * Get whether to log output.
     *
     * Returns null if not explicitly set (uses config default).
     */
    public function getLogOutput(): ?bool
    {
        $value = $this->logOutput;

        if ($value === null) {
            return null;
        }

        return $this->evaluate($value);
    }

    /**
     * Get the log identifier.
     */
    public function getLogIdentifier(): ?string
    {
        $value = $this->logIdentifier;

        if ($value === null) {
            return null;
        }

        return $this->evaluate($value);
    }

    /**
     * Set custom metadata to be included in all log entries for this terminal.
     *
     * This metadata is merged with any existing metadata on each log entry,
     * allowing you to add terminal-specific context like server name, environment,
     * project ID, or any other custom data useful for filtering and analysis.
     *
     * @param  array|Closure  $metadata  Custom metadata key-value pairs
     *
     * @example
     * WebTerminal::make()
     *     ->logMetadata([
     *         'server' => 'production-web-1',
     *         'environment' => 'production',
     *         'project_id' => 123,
     *     ])
     */
    public function logMetadata(array|Closure $metadata): static
    {
        $this->logMetadata = $metadata;

        return $this;
    }

    /**
     * Get the custom log metadata.
     */
    public function getLogMetadata(): array
    {
        return $this->evaluate($this->logMetadata);
    }

}

// Backward compatibility alias
class_alias(WebTerminal::class, 'MWGuerra\\WebTerminal\\Schemas\\Components\\WebTerminalEmbed');
