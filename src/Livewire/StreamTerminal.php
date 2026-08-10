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
use MWGuerra\WebTerminalStream\Security\ConnectionVault;
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

    /**
     * Opaque handle for the connection config, NOT the config itself.
     *
     * Everything public on a Livewire component is serialized into the
     * `wire:snapshot` attribute and delivered to the browser — `#[Locked]`
     * only stops the client changing it on the way back. Holding the resolved
     * config here therefore published the SSH host, username and private key
     * in the page HTML. {@see ConnectionVault}
     */
    #[Locked]
    public string $connectionRef = '';

    /**
     * The connection KIND ('local' / 'ssh') — carried in the clear on purpose.
     * It is not a secret, the chrome reads it on every render, and keeping it
     * out of the vault means a lapsed handle still renders a coherent terminal
     * instead of silently degrading to a local shell.
     */
    #[Locked]
    public string $connectionType = 'local';

    /**
     * Whether this terminal sits in something that can close it.
     *
     * Set by the containers (dashboard source, workspace pane); false for a
     * standalone terminal, which has no window to close. It only governs
     * whether the close dots ADVERTISE themselves as live — the container
     * still decides at click time, because "closable" can change while the
     * page is open (a workspace pane stops being closable once it is the last).
     */
    #[Locked]
    public bool $closable = false;

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
        string $connectionRef = '',
        string $height = '400px',
        string $title = 'Terminal',
        array $theme = [],
        ?string $fontFamily = null,
        ?int $fontSize = null,
        string $chrome = 'full',
        bool $squareCorners = false,
        string $connectionBehavior = 'always',
        bool $closable = false,
        array $scripts = [],
        ?bool $loggingEnabled = null,
        ?bool $logConnections = null,
        ?string $logIdentifier = null,
        array $logMetadata = [],
    ): void {
        // Two entry points meet here. Schema-driven usage arrives with a handle
        // already minted by ResolvesTerminalProperties; standalone Blade/Livewire
        // usage (`@livewire('web-terminal-stream', ['connectionConfig' => [...]])`)
        // still passes a raw array, so take custody of it now — this method is
        // the last place the config exists before Livewire serializes state.
        $this->connectionRef = $connectionRef !== ''
            ? $connectionRef
            : app(ConnectionVault::class)->put($connectionConfig);

        $resolved = $connectionConfig !== [] ? $connectionConfig : $this->connectionConfig();
        $this->connectionType = (ConnectionType::tryFrom($resolved['type'] ?? 'local') ?? ConnectionType::Local)->value;

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

        $config = $this->connectionConfig();

        // An empty config means the vault entry is gone — expired, or minted
        // for a different identity. Say so plainly: falling through would open
        // a PTY somewhere nobody asked for, since an empty config reads as
        // "local shell" to every layer below.
        if ($config === []) {
            return ['error' => 'This terminal session is no longer valid. Reload the page to reconnect.'];
        }

        $reason = (new ConnectionPolicy)->deniedReason($config);
        if ($reason !== null) {
            return ['error' => $reason];
        }

        $sessionId = Str::uuid()->toString();
        $this->sessionId = $sessionId;
        $ttl = config('web-terminal-stream.stream.signed_url_ttl', 300);

        // Encrypted at rest so SSH credentials in the config are not readable
        // in whatever cache store the host uses; the server decrypts on pull.
        Cache::put("terminal-stream-pty:{$sessionId}", encrypt($config), $ttl);

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

        $config = $this->connectionConfig();

        event(new TerminalConnectedEvent(
            sessionId: $this->sessionId,
            connectionType: $this->getConnectionType(),
            host: $config['host'] ?? null,
            port: isset($config['port']) ? (int) $config['port'] : null,
            sshUsername: $config['username'] ?? null,
            userId: auth()->id() !== null ? (string) auth()->id() : null,
            terminalIdentifier: $this->logIdentifier,
            ipAddress: request()?->ip(),
            metadata: $this->logMetadata,
        ));

        $this->getLogger()->logConnection([
            'terminal_session_id' => $this->sessionId,
            'connection_type' => $this->getConnectionType()->value,
            'host' => $config['host'] ?? null,
            'port' => isset($config['port']) ? (int) $config['port'] : null,
            'ssh_username' => $config['username'] ?? null,
        ]);
    }

    public function disconnect(): void
    {
        if (! $this->isConnected) {
            return;
        }

        $this->isConnected = false;

        $config = $this->connectionConfig();

        event(new TerminalDisconnectedEvent(
            sessionId: $this->sessionId,
            connectionType: $this->getConnectionType(),
            host: $config['host'] ?? null,
            port: isset($config['port']) ? (int) $config['port'] : null,
            userId: auth()->id() !== null ? (string) auth()->id() : null,
            terminalIdentifier: $this->logIdentifier,
            ipAddress: request()?->ip(),
            metadata: $this->logMetadata,
        ));

        $this->getLogger()->logDisconnection($this->sessionId, [
            'connection_type' => $this->getConnectionType()->value,
            'host' => $config['host'] ?? null,
            'port' => isset($config['port']) ? (int) $config['port'] : null,
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

    /**
     * The resolved connection config, fetched from server-side custody.
     *
     * Deliberately a method and not a property: a property would be public
     * Livewire state again, which is the whole defect this replaced.
     *
     * @return array<string, mixed>
     */
    public function connectionConfig(): array
    {
        return app(ConnectionVault::class)->get($this->connectionRef);
    }

    protected function getConnectionType(): ConnectionType
    {
        return ConnectionType::tryFrom($this->connectionType) ?? ConnectionType::Local;
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
