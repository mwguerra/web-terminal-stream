<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | Configure terminal session logging to database. This provides detailed
    | tracking of terminal connections and disconnections.
    | These settings can be overridden per-terminal using the ->log() method.
    |
    */
    'logging' => [
        // Global toggle - can be overridden per-terminal
        'enabled' => env('WEB_TERMINAL_STREAM_LOGGING', true),

        // What to log
        'log_connections' => env('WEB_TERMINAL_STREAM_LOG_CONNECTIONS', true),
        'log_disconnections' => env('WEB_TERMINAL_STREAM_LOG_DISCONNECTIONS', true),
        'log_errors' => env('WEB_TERMINAL_STREAM_LOG_ERRORS', true),

        // Output handling (stored in same table, truncated if needed)
        'max_output_length' => env('WEB_TERMINAL_STREAM_MAX_OUTPUT_LOG', 10000),
        'truncate_output' => true,

        // User configuration
        'user_table' => 'users',
        'user_foreign_key' => 'user_id',

        // Retention (cleanup via manual `terminal-stream:logs:cleanup` command)
        'retention_days' => env('WEB_TERMINAL_STREAM_LOG_RETENTION', 90),

        // Specific terminals to log (empty array = all terminals)
        'terminals' => [],

        // Multi-tenant support
        // Set to 'tenant_id' or your tenant column name if using tenancy
        'tenant_column' => null,

        // Custom resolver callback - receives no args, should return tenant ID or null
        // Example: fn () => auth()->user()?->tenant_id
        // Example: TenantResolver::class (must implement __invoke)
        'tenant_resolver' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Stream Terminal
    |--------------------------------------------------------------------------
    |
    | Configuration for the stream terminal. It provides a full interactive
    | PTY shell via WebSocket, using the ghostty-web WASM terminal emulator.
    | Start the WebSocket server with `php artisan terminal-stream:serve`.
    |
    */
    'stream' => [
        'websocket_provider' => 'ratchet',
        'ratchet_host' => env('WEB_TERMINAL_STREAM_RATCHET_HOST', '127.0.0.1'),
        'ratchet_port' => env('WEB_TERMINAL_STREAM_RATCHET_PORT', 8090),
        'websocket_url' => env('WEB_TERMINAL_STREAM_WEBSOCKET_URL'),
        'ssl_cert' => env('WEB_TERMINAL_STREAM_SSL_CERT'),
        'ssl_key' => env('WEB_TERMINAL_STREAM_SSL_KEY'),
        'shell' => env('WEB_TERMINAL_STREAM_SHELL', '/bin/bash'),
        'working_directory' => env('WEB_TERMINAL_STREAM_CWD'),
        'pty_grace_period' => 30,
        'max_session_lifetime' => 3600,
        'signed_url_ttl' => 300,
        'allowed_origins' => [env('APP_URL', 'http://localhost')],
        'theme' => [
            'background' => '#1a1b26',
            'foreground' => '#a9b1d6',
            'fontSize' => 14,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Deprecation Notices
    |--------------------------------------------------------------------------
    |
    | When `emit_notices` is true, deprecated fluent-API methods trigger a
    | PHP E_USER_DEPRECATED notice when called. Off by default so users
    | aren't flooded; flip it on in staging to surface usage before the
    | next major upgrade.
    |
    */
    'deprecations' => [
        'emit_notices' => env('WEB_TERMINAL_STREAM_DEPRECATIONS_EMIT_NOTICES', false),
    ],
];
