<?php

declare(strict_types=1);

namespace MWGuerra\WebTerminalStream\Enums;

enum ConnectionType: string
{
    case Local = 'local';
    case SSH = 'ssh';

    /**
     * Get a human-readable label for the connection type.
     */
    public function label(): string
    {
        return match ($this) {
            self::Local => 'Local',
            self::SSH => 'SSH',
        };
    }

    /**
     * Get a description of the connection type.
     */
    public function description(): string
    {
        return match ($this) {
            self::Local => 'Execute commands on the local server',
            self::SSH => 'Execute commands via SSH connection',
        };
    }

    /**
     * Check if this connection type requires authentication credentials.
     */
    public function requiresCredentials(): bool
    {
        return match ($this) {
            self::Local => false,
            self::SSH => true,
        };
    }

    /**
     * Get the default port for this connection type.
     */
    public function defaultPort(): ?int
    {
        return match ($this) {
            self::Local => null,
            self::SSH => 22,
        };
    }

    /**
     * Get all available connection types as an array for form selects.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_combine(
            array_column(self::cases(), 'value'),
            array_map(fn (self $case) => $case->label(), self::cases())
        );
    }
}
