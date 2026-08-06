<?php

declare(strict_types=1);

namespace MWGuerra\WebTerminalStream\Listeners;

use MWGuerra\WebTerminalStream\Events\TerminalConnectedEvent;
use MWGuerra\WebTerminalStream\Events\TerminalDisconnectedEvent;
use MWGuerra\WebTerminalStream\Services\TerminalLogger;

/**
 * Event listener for terminal logging.
 *
 * This listener provides an alternative event-based approach to logging.
 * Register this listener manually if you prefer the event-driven pattern.
 *
 * Note: The Livewire component already performs direct logging, so using
 * both approaches would result in duplicate log entries.
 */
class TerminalLogListener
{
    /**
     * Create the event listener.
     */
    public function __construct(
        protected TerminalLogger $logger
    ) {}

    /**
     * Handle terminal connected events.
     */
    public function handleConnected(TerminalConnectedEvent $event): void
    {
        $this->logger->logConnection([
            'terminal_session_id' => $event->sessionId,
            'terminal_identifier' => $event->terminalIdentifier,
            'connection_type' => $event->connectionType->value,
            'host' => $event->host,
            'port' => $event->port,
            'ssh_username' => $event->sshUsername,
            'ip_address' => $event->ipAddress,
            'metadata' => $event->metadata,
        ]);
    }

    /**
     * Handle terminal disconnected events.
     */
    public function handleDisconnected(TerminalDisconnectedEvent $event): void
    {
        $this->logger->logDisconnection($event->sessionId, [
            'terminal_identifier' => $event->terminalIdentifier,
            'connection_type' => $event->connectionType->value,
            'host' => $event->host,
            'port' => $event->port,
            'ip_address' => $event->ipAddress,
            'metadata' => $event->metadata,
        ]);
    }

    /**
     * Register the listeners for the subscriber.
     *
     * @return array<string, string>
     */
    public function subscribe(): array
    {
        return [
            TerminalConnectedEvent::class => 'handleConnected',
            TerminalDisconnectedEvent::class => 'handleDisconnected',
        ];
    }
}
