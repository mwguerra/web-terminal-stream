<?php

declare(strict_types=1);

namespace MWGuerra\WebTerminalStream\Livewire;

use Illuminate\Support\HtmlString;
use Livewire\Livewire;
use MWGuerra\WebTerminalStream\Concerns\ConfiguresLogging;
use MWGuerra\WebTerminalStream\Concerns\ConfiguresScripts;
use MWGuerra\WebTerminalStream\Concerns\ConfiguresStreamMode;
use MWGuerra\WebTerminalStream\Concerns\ConfiguresTerminalAppearance;
use MWGuerra\WebTerminalStream\Concerns\EvaluatesOptions;
use MWGuerra\WebTerminalStream\Data\ConnectionConfig;
use MWGuerra\WebTerminalStream\Enums\ConnectionType;

/**
 * Fluent builder for the Stream terminal component.
 *
 * Provides a clean, chainable API for configuring the terminal
 * before rendering it in a Blade view.
 */
class TerminalBuilder
{
    use ConfiguresLogging;
    use ConfiguresScripts;
    use ConfiguresStreamMode;
    use ConfiguresTerminalAppearance;
    use EvaluatesOptions;

    /** @var array<string, mixed>|ConnectionConfig|null */
    protected array|ConnectionConfig|null $connection = null;

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
        $connectionArray = match (true) {
            $this->connection instanceof ConnectionConfig => $this->connection->toArray(),
            is_array($this->connection) => $this->connection,
            default => [],
        };

        return [
            'connectionConfig' => $connectionArray,
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

    public function render(): HtmlString
    {
        $params = $this->getParameters();

        if ($this->key !== null) {
            return new HtmlString(
                Livewire::mount('web-terminal-stream', $params, $this->key)
            );
        }

        return new HtmlString(
            Livewire::mount('web-terminal-stream', $params)
        );
    }
}
