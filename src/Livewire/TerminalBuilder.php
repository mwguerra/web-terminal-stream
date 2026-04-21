<?php

declare(strict_types=1);

namespace MWGuerra\WebTerminal\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\HtmlString;
use MWGuerra\WebTerminal\Concerns\ConfiguresCommandPresets;
use MWGuerra\WebTerminal\Concerns\ConfiguresLogging;
use MWGuerra\WebTerminal\Concerns\EmitsDeprecationNotices;
use MWGuerra\WebTerminal\Concerns\ConfiguresPermissions;
use MWGuerra\WebTerminal\Concerns\ConfiguresScripts;
use MWGuerra\WebTerminal\Concerns\ConfiguresSessionManagement;
use MWGuerra\WebTerminal\Concerns\ConfiguresShellEnvironment;
use MWGuerra\WebTerminal\Concerns\ConfiguresStreamMode;
use MWGuerra\WebTerminal\Concerns\ConfiguresTerminalAppearance;
use MWGuerra\WebTerminal\Concerns\ConfiguresTerminalBasics;
use MWGuerra\WebTerminal\Concerns\EvaluatesOptions;
use MWGuerra\WebTerminal\Data\ConnectionConfig;
use MWGuerra\WebTerminal\Enums\ConnectionType;
use MWGuerra\WebTerminal\Enums\TerminalMode;

/**
 * Fluent builder for WebTerminal component.
 *
 * Provides a clean, chainable API for configuring the terminal
 * before rendering it in a Blade view.
 */
class TerminalBuilder
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
    use EmitsDeprecationNotices;
    use EvaluatesOptions;

    /** @var array<string, mixed>|ConnectionConfig|null */
    protected array|ConnectionConfig|null $connection = null;

    /** @var array<string>|null */
    protected ?array $allowedCommands = null;

    protected ?string $key = null;


    // ========================================
    // Connection Configuration
    // ========================================

    /**
     * @param  array<string, mixed>  $config
     */
    public function connection(ConnectionType|string $type, array $config = []): static
    {
        if (is_string($type)) {
            $type = ConnectionType::from($type);
        }

        $this->connection = array_merge(['type' => $type->value], $config);

        return $this;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function local(array $options = []): static
    {
        return $this->connection(ConnectionType::Local, $options);
    }

    public function sshWithPassword(
        string $host,
        string $username,
        string $password,
        ?int $port = null,
    ): static {
        return $this->connection(ConnectionType::SSH, [
            'host' => $host,
            'username' => $username,
            'password' => $password,
            'port' => $port,
        ]);
    }

    public function sshWithKey(
        string $host,
        string $username,
        string $privateKey,
        ?string $passphrase = null,
        ?int $port = null,
    ): static {
        return $this->connection(ConnectionType::SSH, [
            'host' => $host,
            'username' => $username,
            'private_key' => $privateKey,
            'passphrase' => $passphrase,
            'port' => $port,
        ]);
    }

    public function withConfig(ConnectionConfig $config): static
    {
        $this->connection = $config;

        return $this;
    }

    // ========================================
    // Command Configuration
    // ========================================

    /** @param  array<string>  $commands */
    public function allowedCommands(array $commands): static
    {
        $this->allowedCommands = $commands;

        return $this;
    }

    /** @param  array<string>  $commands */
    public function addAllowedCommands(array $commands): static
    {
        $existing = $this->allowedCommands ?? config('web-terminal.allowed_commands', []);
        $this->allowedCommands = array_unique(array_merge($existing, $commands));

        return $this;
    }

    // ========================================
    // UI Configuration
    // ========================================

    public function key(string $key): static
    {
        $this->key = $key;

        return $this;
    }

    // ========================================
    // Build & Render
    // ========================================

    /** @return array<string, mixed> */
    public function getParameters(): array
    {
        $params = array_filter([
            'connection' => $this->connection,
            'allowedCommands' => $this->allowedCommands,
            'timeout' => $this->timeout,
            'prompt' => $this->prompt,
            'historyLimit' => $this->historyLimit,
            'maxOutputLines' => $this->maxOutputLines,
            'height' => $this->height,
            'disconnectOnNavigate' => $this->getDisconnectOnNavigate(),
            'inactivityTimeout' => $this->getInactivityTimeout(),
            'loggingEnabled' => $this->getLoggingEnabled(),
            'logConnections' => $this->getLogConnections(),
            'logCommands' => $this->getLogCommands(),
            'logOutput' => $this->getLogOutput(),
            'logIdentifier' => $this->getLogIdentifier(),
            'title' => $this->title,
        ], fn ($value) => $value !== null);

        // Boolean flags — include when true
        if ($this->getAllowAll()) {
            $params['allowAllCommands'] = true;
        }
        if ($this->getAllowPipes()) {
            $params['allowPipes'] = true;
        }
        if ($this->getAllowRedirection()) {
            $params['allowRedirection'] = true;
        }
        if ($this->getAllowChaining()) {
            $params['allowChaining'] = true;
        }
        if ($this->getAllowExpansion()) {
            $params['allowExpansion'] = true;
        }
        if ($this->getAllowAllShellOperators()) {
            $params['allowAllShellOperators'] = true;
        }
        if ($this->getAllowInteractiveMode()) {
            $params['allowInteractiveMode'] = true;
        }
        if ($this->getStartConnected()) {
            $params['startConnected'] = true;
        }
        if (! $this->getShowWindowControls()) {
            $params['showWindowControls'] = false;
        }
        if ($this->getUseLoginShell()) {
            $params['useLoginShell'] = true;
        }
        if ($this->getShell() !== '/bin/bash') {
            $params['shell'] = $this->getShell();
        }
        $environment = $this->getEnvironment();
        if (! empty($environment)) {
            $params['environment'] = $environment;
        }
        $logMetadata = $this->getLogMetadata();
        if (! empty($logMetadata)) {
            $params['logMetadata'] = $logMetadata;
        }
        $scripts = $this->getScripts();
        if (! empty($scripts)) {
            $params['scripts'] = $scripts;
        }

        // Stream mode params — only include non-default values
        if ($this->getStreamEnabled()) {
            $params['streamEnabled'] = true;
            $params['streamTheme'] = $this->getStreamTheme();
        }
        if (! $this->getClassicEnabled()) {
            $params['classicEnabled'] = false;
        }
        $params['defaultMode'] = $this->defaultMode->value;

        return $params;
    }

    public function render(): View|HtmlString
    {
        if (! $this->classicEnabled && ! $this->streamEnabled) {
            throw new \InvalidArgumentException('At least one terminal mode must be enabled');
        }

        if ($this->defaultMode === TerminalMode::Stream && ! $this->streamEnabled) {
            throw new \InvalidArgumentException('Cannot set default mode to Stream when Stream is disabled');
        }

        if ($this->defaultMode === TerminalMode::Classic && ! $this->classicEnabled) {
            throw new \InvalidArgumentException('Cannot set default mode to Classic when Classic is disabled');
        }

        $params = $this->getParameters();
        $key = $this->key;

        // Determine which component to mount
        $connectionArray = match (true) {
            $this->connection instanceof ConnectionConfig => $this->connection->toArray(),
            is_array($this->connection) => $this->connection,
            default => [],
        };

        if ($this->getStreamEnabled() && $this->getClassicEnabled()) {
            $component = 'terminal-container';
            $mountParams = [
                'classicParams' => $params,
                'streamParams' => [
                    'connectionConfig' => $connectionArray,
                    'streamTheme' => $this->getStreamTheme(),
                    'scripts' => $this->getScripts(),
                ],
                'defaultMode' => $this->defaultMode->value,
                'height' => $this->getHeight(),
                'title' => $this->getTitle(),
                'showWindowControls' => $this->getShowWindowControls(),
            ];
        } elseif ($this->getStreamEnabled()) {
            $component = 'stream-terminal';
            $mountParams = [
                'connectionConfig' => $connectionArray,
                'height' => $this->getHeight(),
                'title' => $this->getTitle(),
                'streamTheme' => $this->getStreamTheme(),
                'showWindowControls' => $this->getShowWindowControls(),
                'scripts' => $this->getScripts(),
            ];
        } else {
            $component = 'web-terminal';
            $mountParams = $params;
        }

        if ($key !== null) {
            return new HtmlString(
                \Livewire\Livewire::mount($component, $mountParams, $key)
            );
        }

        return new HtmlString(
            \Livewire\Livewire::mount($component, $mountParams)
        );
    }

    /**
     * @deprecated since 2.x, will be removed in 3.0.
     *             Use `render()` instead — it supports every configuration option
     *             on the builder, while `toHtml()` silently omits Stream-mode
     *             props, logging config, scripts, session management, etc.
     */
    public function toHtml(): string
    {
        $this->emitDeprecationNotice('TerminalBuilder::toHtml()', 'TerminalBuilder::render()');

        $params = [];

        if ($this->connection !== null) {
            if ($this->connection instanceof ConnectionConfig) {
                $params[':connection'] = '$connection';
            } else {
                $params[':connection'] = json_encode($this->connection);
            }
        }

        if ($this->allowedCommands !== null) {
            $params[':allowed-commands'] = json_encode($this->allowedCommands);
        }

        if ($this->timeout !== null) {
            $params[':timeout'] = $this->timeout;
        }

        if ($this->prompt !== null) {
            $params['prompt'] = $this->prompt;
        }

        if ($this->historyLimit !== null) {
            $params[':history-limit'] = $this->historyLimit;
        }

        if ($this->maxOutputLines !== null) {
            $params[':max-output-lines'] = $this->maxOutputLines;
        }

        if ($this->height !== null) {
            $params['height'] = $this->height;
        }

        if ($this->getAllowAll()) {
            $params[':allow-all-commands'] = 'true';
        }

        if ($this->getAllowAllShellOperators()) {
            $params[':allow-all-shell-operators'] = 'true';
        }

        if ($this->getAllowInteractiveMode()) {
            $params[':allow-interactive-mode'] = 'true';
        }

        if ($this->getStartConnected()) {
            $params[':start-connected'] = 'true';
        }

        if ($this->disconnectOnNavigate !== null) {
            $params[':disconnect-on-navigate'] = $this->disconnectOnNavigate ? 'true' : 'false';
        }

        if ($this->inactivityTimeout !== null) {
            $params[':inactivity-timeout'] = $this->inactivityTimeout;
        }

        $paramsString = collect($params)
            ->map(fn ($value, $key) => "{$key}=\"{$value}\"")
            ->implode(' ');

        $keyAttr = $this->key ? " wire:key=\"{$this->key}\"" : '';

        return "<livewire:web-terminal {$paramsString}{$keyAttr} />";
    }

    /**
     * @deprecated since 2.x, will be removed in 3.0.
     *             Delegates to the deprecated `toHtml()` path. Prefer
     *             explicitly calling `render()` where you need the HTML.
     */
    public function __toString(): string
    {
        return $this->toHtml();
    }
}
