<?php

declare(strict_types=1);

namespace MWGuerra\WebTerminalStream\Livewire;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;
use MWGuerra\WebTerminalStream\Enums\ConnectionBehavior;
use MWGuerra\WebTerminalStream\Enums\ConnectionType;
use MWGuerra\WebTerminalStream\Events\TerminalConnectedEvent;
use MWGuerra\WebTerminalStream\Events\TerminalDisconnectedEvent;
use MWGuerra\WebTerminalStream\Security\ConnectionPolicy;
use MWGuerra\WebTerminalStream\Services\TerminalLogger;

class StreamTerminal extends Component
{
    public bool $isConnected = false;

    public string $height = '400px';

    public string $title = 'Terminal';

    public string $chrome = 'full';

    public bool $squareCorners = false;

    public string $connectionBehavior = 'always';

    #[Locked]
    public array $theme = [];

    #[Locked]
    public ?string $fontFamily = null;

    #[Locked]
    public ?int $fontSize = null;

    #[Locked]
    public array $connectionConfig = [];

    #[Locked]
    public string $componentId = '';

    #[Locked]
    public array $scripts = [];

    #[Locked]
    public string $sessionId = '';

    #[Locked]
    public ?bool $loggingEnabled = null;

    #[Locked]
    public ?bool $logConnections = null;

    #[Locked]
    public ?string $logIdentifier = null;

    #[Locked]
    public array $logMetadata = [];

    public function mount(
        array $connectionConfig = [],
        string $height = '400px',
        string $title = 'Terminal',
        array $theme = [],
        ?string $fontFamily = null,
        ?int $fontSize = null,
        string $chrome = 'full',
        bool $squareCorners = false,
        string $connectionBehavior = 'always',
        array $scripts = [],
        ?bool $loggingEnabled = null,
        ?bool $logConnections = null,
        ?string $logIdentifier = null,
        array $logMetadata = [],
    ): void {
        $this->connectionConfig = $connectionConfig;
        $this->height = $height;
        $this->title = $title;
        $this->theme = $theme;
        $this->fontFamily = $fontFamily;
        $this->fontSize = $fontSize;
        $this->chrome = in_array($chrome, ['full', 'minimal', 'none'], true) ? $chrome : 'full';
        $this->squareCorners = $squareCorners;
        $this->connectionBehavior = (ConnectionBehavior::tryFrom($connectionBehavior) ?? ConnectionBehavior::Always)->value;
        $this->scripts = $scripts;
        $this->loggingEnabled = $loggingEnabled;
        $this->logConnections = $logConnections;
        $this->logIdentifier = $logIdentifier;
        $this->logMetadata = $logMetadata;
        $this->componentId = 'stream-'.Str::random(8);
    }

    public function getWebSocketUrl(): array
    {
        if (Gate::has('useStreamTerminal') && ! Gate::allows('useStreamTerminal')) {
            return ['error' => 'Unauthorized'];
        }

        $reason = (new ConnectionPolicy)->deniedReason($this->connectionConfig);
        if ($reason !== null) {
            return ['error' => $reason];
        }

        $sessionId = Str::uuid()->toString();
        $this->sessionId = $sessionId;
        $ttl = config('web-terminal-stream.stream.signed_url_ttl', 300);

        // Encrypted at rest so SSH credentials in the config are not readable
        // in whatever cache store the host uses; the server decrypts on pull.
        Cache::put("terminal-stream-pty:{$sessionId}", encrypt($this->connectionConfig), $ttl);

        $payload = json_encode([
            'userId' => auth()->id(),
            'sessionId' => $sessionId,
            'exp' => time() + $ttl,
        ]);

        $token = app('encrypter')->encrypt($payload);
        $encodedToken = urlencode($token);

        $wsUrl = config('web-terminal-stream.stream.websocket_url');
        if ($wsUrl) {
            $separator = str_contains($wsUrl, '?') ? '&' : '?';
            $url = "{$wsUrl}{$separator}token={$encodedToken}";
        } else {
            $host = config('web-terminal-stream.stream.ratchet_host', '127.0.0.1');
            $port = config('web-terminal-stream.stream.ratchet_port', 8090);
            $url = "ws://{$host}:{$port}?token={$encodedToken}";
        }

        return [
            'token' => $token,
            'url' => $url,
            'sessionId' => $sessionId,
        ];
    }

    public function connect(): void
    {
        if ($this->isConnected || ! $this->mayUseTerminal()) {
            return;
        }

        $this->isConnected = true;

        if ($this->sessionId === '') {
            $this->sessionId = Str::uuid()->toString();
        }

        event(new TerminalConnectedEvent(
            sessionId: $this->sessionId,
            connectionType: $this->getConnectionType(),
            host: $this->connectionConfig['host'] ?? null,
            port: isset($this->connectionConfig['port']) ? (int) $this->connectionConfig['port'] : null,
            sshUsername: $this->connectionConfig['username'] ?? null,
            userId: auth()->id() !== null ? (string) auth()->id() : null,
            terminalIdentifier: $this->logIdentifier,
            ipAddress: request()?->ip(),
            metadata: $this->logMetadata,
        ));

        $this->getLogger()->logConnection([
            'terminal_session_id' => $this->sessionId,
            'connection_type' => $this->getConnectionType()->value,
            'host' => $this->connectionConfig['host'] ?? null,
            'port' => isset($this->connectionConfig['port']) ? (int) $this->connectionConfig['port'] : null,
            'ssh_username' => $this->connectionConfig['username'] ?? null,
        ]);
    }

    public function disconnect(): void
    {
        if (! $this->isConnected) {
            return;
        }

        $this->isConnected = false;

        event(new TerminalDisconnectedEvent(
            sessionId: $this->sessionId,
            connectionType: $this->getConnectionType(),
            host: $this->connectionConfig['host'] ?? null,
            port: isset($this->connectionConfig['port']) ? (int) $this->connectionConfig['port'] : null,
            userId: auth()->id() !== null ? (string) auth()->id() : null,
            terminalIdentifier: $this->logIdentifier,
            ipAddress: request()?->ip(),
            metadata: $this->logMetadata,
        ));

        $this->getLogger()->logDisconnection($this->sessionId, [
            'connection_type' => $this->getConnectionType()->value,
            'host' => $this->connectionConfig['host'] ?? null,
            'port' => isset($this->connectionConfig['port']) ? (int) $this->connectionConfig['port'] : null,
        ]);
    }

    public function getScriptsForExecution(string $key): array
    {
        foreach ($this->scripts as $script) {
            if (($script['key'] ?? '') === $key) {
                return $script['commands'] ?? [];
            }
        }

        return [];
    }

    protected function mayUseTerminal(): bool
    {
        return ! Gate::has('useStreamTerminal') || Gate::allows('useStreamTerminal');
    }

    protected function getConnectionType(): ConnectionType
    {
        return ConnectionType::tryFrom($this->connectionConfig['type'] ?? 'local') ?? ConnectionType::Local;
    }

    protected function getLogger(): TerminalLogger
    {
        $overrides = array_filter([
            'enabled' => $this->loggingEnabled,
            'connections' => $this->logConnections,
            'disconnections' => $this->logConnections,
            'identifier' => $this->logIdentifier,
            'metadata' => $this->logMetadata !== [] ? $this->logMetadata : null,
        ], fn ($value) => $value !== null);

        return app(TerminalLogger::class)->withOverrides($overrides);
    }

    public function render()
    {
        return view('web-terminal-stream::stream-terminal');
    }
}
