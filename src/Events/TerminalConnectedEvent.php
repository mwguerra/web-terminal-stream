<?php

declare(strict_types=1);

namespace MWGuerra\WebTerminalStream\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use MWGuerra\WebTerminalStream\Enums\ConnectionType;

/**
 * Event dispatched when a terminal connection is established.
 */
class TerminalConnectedEvent
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public readonly string $sessionId,
        public readonly ConnectionType $connectionType,
        public readonly ?string $host = null,
        public readonly ?int $port = null,
        public readonly ?string $sshUsername = null,
        public readonly ?string $userId = null,
        public readonly ?string $terminalIdentifier = null,
        public readonly ?string $ipAddress = null,
        public readonly array $metadata = [],
    ) {}

    /**
     * Get the event as an array for logging.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'session_id' => $this->sessionId,
            'connection_type' => $this->connectionType->value,
            'host' => $this->host,
            'port' => $this->port,
            'ssh_username' => $this->sshUsername,
            'user_id' => $this->userId,
            'terminal_identifier' => $this->terminalIdentifier,
            'ip_address' => $this->ipAddress,
            'metadata' => $this->metadata,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
