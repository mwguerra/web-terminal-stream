<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Security
    |--------------------------------------------------------------------------
    |
    | This package streams a real interactive shell. Access control is the
    | boundary (there is no command whitelist — a PTY cannot be meaningfully
    | whitelisted). Beyond page-level authz and the optional `useStreamTerminal`
    | Gate, these settings constrain WHAT a token may connect to. They are
    | enforced on every token-issuance path AND re-checked on the WebSocket
    | server before a PTY is started (defense in depth).
    |
    */
    'security' => [
        // Whether local-shell terminals are permitted at all. Set false to
        // allow only SSH connections so the app host's own shell is never
        // exposed through the browser.
        'allow_local' => env('WEB_TERMINAL_STREAM_ALLOW_LOCAL', true),

        // Whether POST /terminal-stream/ws-token may accept a whole connection
        // config from the CLIENT. That is the documented standalone flow, but it
        // means the app server dials wherever the caller says — with an empty
        // `ssh_allowed_hosts` below, an SSH/SSRF pivot behind nothing but the
        // Gate. Apps that only use the schema components send an opaque
        // `connectionRef` instead and should turn this off.
        'allow_client_supplied_connections' => env('WEB_TERMINAL_STREAM_ALLOW_CLIENT_CONNECTIONS', true),

        // SSH destination allow-list. Empty = any host allowed — NOT
        // recommended in production, since the server becomes an SSH/SSRF
        // pivot. List exact hostnames/IPs, optionally as "host:port" to pin
        // the port. Enforced server-side; a token for a disallowed host is
        // refused even if it was somehow minted.
        'ssh_allowed_hosts' => [],

        // Rate limit for the ws-token issuance route, "<maxAttempts>,<minutes>".
        'token_rate_limit' => env('WEB_TERMINAL_STREAM_TOKEN_RATE_LIMIT', '30,1'),

        // SSH host-key verification for OUTBOUND connections. phpseclib does
        // not verify server host keys by default, so 'off' leaves SSH sessions
        // open to MITM. Set 'known_hosts' and point known_hosts_path at an
        // OpenSSH known_hosts file, or 'fingerprints' and list sha256
        // fingerprints per host, to require verification.
        'ssh_host_key' => [
            'mode' => env('WEB_TERMINAL_STREAM_SSH_HOSTKEY_MODE', 'off'), // off | known_hosts | fingerprints
            'known_hosts_path' => env('WEB_TERMINAL_STREAM_SSH_KNOWN_HOSTS'),
            'fingerprints' => [], // ['host' => 'SHA256:...'] or ['host:port' => '...']
        ],
    ],

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
        'ratchet_host' => env('WEB_TERMINAL_STREAM_RATCHET_HOST', '127.0.0.1'),
        'ratchet_port' => env('WEB_TERMINAL_STREAM_RATCHET_PORT', 8090),
        'websocket_url' => env('WEB_TERMINAL_STREAM_WEBSOCKET_URL'),
        'ssl_cert' => env('WEB_TERMINAL_STREAM_SSL_CERT'),
        'ssl_key' => env('WEB_TERMINAL_STREAM_SSL_KEY'),
        'shell' => env('WEB_TERMINAL_STREAM_SHELL', '/bin/bash'),
        'working_directory' => env('WEB_TERMINAL_STREAM_CWD'),
        // How the server learns that a PTY produced output.
        //
        // 'event' (default): each session's transport is registered with the
        // event loop, which wakes the server exactly when bytes arrive. An
        // idle terminal then costs nothing.
        //
        // 'poll': the pre-1.1.1 behaviour — sweep every session on a 10ms
        // timer, where each SSH read waits up to its own timeout even when
        // there is nothing to read. That made round-trip latency scale with
        // the NUMBER OF OPEN TERMINALS: ~60ms at 1 session, ~2000ms at 100.
        // Kept only as an escape hatch; prefer fixing forward.
        'io_mode' => env('WEB_TERMINAL_STREAM_IO_MODE', 'event'),

        // Worker processes sharing the listening port (SO_REUSEPORT).
        //
        // 1 (default) keeps the historical single-process behaviour. Above 1,
        // `terminal-stream:serve` forks that many servers and supervises them.
        //
        // The reason to raise it: a session's SSH connect + auth runs
        // synchronously on its server's event loop, so every session on that
        // loop stalls while another connects. More loops means a connect storm
        // freezes 1/N of the fleet instead of all of it, and the phpseclib
        // crypto spreads across cores. A good starting point is the number of
        // cores you are willing to give the terminal server.
        //
        // NOTE: `max_connections` and `max_sessions_per_user` below are
        // enforced PER WORKER, because workers share nothing. With 4 workers
        // and max_connections 100, the fleet ceiling is 400.
        'workers' => env('WEB_TERMINAL_STREAM_WORKERS', 1),

        // Backstop sweep for event mode. phpseclib can hold decoded bytes in
        // its own buffer after the socket stops being readable, so a slow
        // sweep guarantees such output is still delivered. Lower = snappier
        // worst case, higher = cheaper idle. Ignored in 'poll' mode.
        'backstop_sweep_seconds' => env('WEB_TERMINAL_STREAM_BACKSTOP_SWEEP', 0.25),

        // How often each worker republishes its metrics snapshot. Read by
        // MetricsReader / the Filament widgets; a snapshot older than
        // `metrics.stale_after_seconds` counts as a dead worker.
        'metrics_interval_seconds' => env('WEB_TERMINAL_STREAM_METRICS_INTERVAL', 5),

        'max_session_lifetime' => 3600,
        'signed_url_ttl' => 300,

        // How long a resolved connection config stays in server-side custody
        // (ConnectionVault) while its terminal sits on screen. The window slides
        // on every read, so this is an IDLE timeout, not a total one: a page has
        // to survive being left open over lunch and still connect. Past it, the
        // terminal reports that it needs a reload rather than connecting to
        // anything unexpected.
        'connection_ttl' => env('WEB_TERMINAL_STREAM_CONNECTION_TTL', 7200),

        // Resilience limits for the long-running WebSocket server. The server
        // is a single process holding one PTY per connection, so unbounded
        // growth is a real denial-of-service surface.
        //
        // Total live PTYs the server will hold at once (0 = unlimited).
        'max_connections' => env('WEB_TERMINAL_STREAM_MAX_CONNECTIONS', 100),
        // Live PTYs a single authenticated user may hold (0 = unlimited).
        'max_sessions_per_user' => env('WEB_TERMINAL_STREAM_MAX_SESSIONS_PER_USER', 10),
        // Cap on the HTTP upgrade request before the handshake completes, so a
        // client that never sends the terminating CRLF cannot grow memory.
        'max_handshake_bytes' => env('WEB_TERMINAL_STREAM_MAX_HANDSHAKE_BYTES', 16384),

        // Origins allowed to open a WebSocket handshake (CSRF-shaped defense
        // in depth on top of the single-use token). Matched on normalized
        // scheme + host + port; requests without an Origin header (non-browser
        // clients) are always allowed. A literal '*' entry disables the check
        // (escape hatch for proxies that strip or rewrite Origin). This is an
        // array, so it is config-file-only — there is no env var for it.
        'allowed_origins' => [
            env('APP_URL', 'http://localhost'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Terminal Workspace (tmux-style tiling)
    |--------------------------------------------------------------------------
    |
    | Defaults for the TerminalWorkspace component: keyboard shortcuts and
    | pane limits. Every value can be overridden per component through the
    | fluent API (->keymap(), ->maxPanes(), ...); fluent wins over config.
    |
    | Shortcut bindings are keyed by the PaneAction backed values. Key
    | strings are lowercase, '+'-joined modifiers (ctrl|alt|shift|meta)
    | followed by a KeyboardEvent.key value. Omitted actions keep the
    | tmux preset; bind an action to [] to disable it.
    |
    */
    'workspace' => [
        'shortcuts' => [
            'enabled' => env('WEB_TERMINAL_STREAM_SHORTCUTS', true),
            'prefix' => 'ctrl+b',
            'prefix_timeout' => 1500,
            'bindings' => [
                // 'split_horizontal' => ['%'],
                // 'split_vertical'   => ['"'],
                // 'close_pane'       => ['x'],
                // 'zoom_pane'        => ['z'],
                // 'focus_left'       => ['arrowleft', 'h'],
                // 'focus_right'      => ['arrowright', 'l'],
                // 'focus_up'         => ['arrowup', 'k'],
                // 'focus_down'       => ['arrowdown', 'j'],
                // 'resize_left'      => ['ctrl+arrowleft'],
                // 'resize_right'     => ['ctrl+arrowright'],
                // 'resize_up'        => ['ctrl+arrowup'],
                // 'resize_down'      => ['ctrl+arrowdown'],
            ],
        ],

        // Hard ceiling on panes per workspace, enforced server-side.
        'max_panes' => 9,

        // A pane can never be dragged/resized below this share of its split.
        'min_pane_ratio' => 0.10,

        // Ratio nudge applied by each keyboard resize step.
        'resize_step' => 0.03,
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics
    |--------------------------------------------------------------------------
    |
    | Each WebSocket worker publishes a snapshot (live sessions, connect and
    | sweep latency, refusals) that MetricsReader and the Filament widgets read.
    | Workers share nothing, so the fleet view is assembled from per-worker
    | files — and a worker that dies leaves a snapshot behind, which is why a
    | staleness window exists rather than trusting whatever is on disk.
    |
    */
    'metrics' => [
        // Past this age a snapshot is reported as a DEAD worker instead of
        // being merged into the fleet totals. Keep it comfortably above
        // stream.metrics_interval_seconds.
        'stale_after_seconds' => env('WEB_TERMINAL_STREAM_METRICS_STALE_AFTER', 30),
    ],
];
