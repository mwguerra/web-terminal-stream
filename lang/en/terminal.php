<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Navigation
    |--------------------------------------------------------------------------
    */
    'navigation' => [
        'terminal' => 'Terminal',
        'terminal_logs' => 'Terminal Logs',
        'tools' => 'Tools',
    ],

    /*
    |--------------------------------------------------------------------------
    | Pages
    |--------------------------------------------------------------------------
    */
    'pages' => [
        'terminal' => [
            'title' => 'Terminal',
            'local_terminal' => 'Local Terminal',
            'local_terminal_description' => 'Execute commands on the local system.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Resource
    |--------------------------------------------------------------------------
    */
    'resource' => [
        'label' => 'Terminal Log',
        'plural_label' => 'Terminal Logs',
        'back' => 'Back',
    ],

    /*
    |--------------------------------------------------------------------------
    | Table Columns
    |--------------------------------------------------------------------------
    */
    'table' => [
        'time' => 'Time',
        'event' => 'Event',
        'terminal' => 'Terminal',
        'type' => 'Type',
        'user' => 'User',
        'command' => 'Command',
        'exit' => 'Exit',
        'host' => 'Host',
        'session_id' => 'Session ID',
        'ip_address' => 'IP Address',
        'duration' => 'Duration',
        'system' => 'System',
        'localhost' => 'localhost',
    ],

    /*
    |--------------------------------------------------------------------------
    | Table Filters
    |--------------------------------------------------------------------------
    */
    'filters' => [
        'event_type' => 'Event Type',
        'connection_type' => 'Connection Type',
        'user' => 'User',
        'terminal' => 'Terminal',
        'failed_commands_only' => 'Failed Commands Only',
        'from' => 'From',
        'until' => 'Until',
    ],

    /*
    |--------------------------------------------------------------------------
    | Event Types
    |--------------------------------------------------------------------------
    */
    'events' => [
        'connected' => 'Connected',
        'disconnected' => 'Disconnected',
        'command' => 'Command',
        'output' => 'Output',
        'error' => 'Error',
        'blocked' => 'Blocked',
    ],

    /*
    |--------------------------------------------------------------------------
    | Connection Types
    |--------------------------------------------------------------------------
    */
    'connection_types' => [
        'local' => 'Local',
        'ssh' => 'SSH',
    ],

    /*
    |--------------------------------------------------------------------------
    | Infolist (View Page)
    |--------------------------------------------------------------------------
    */
    'infolist' => [
        'event_information' => 'Event Information',
        'event_type' => 'Event Type',
        'connection_type' => 'Connection Type',
        'timing' => 'Timing',
        'timestamp' => 'Timestamp',
        'execution_time' => 'Execution Time',
        'seconds' => ':count seconds',
        'user_session' => 'User & Session',
        'user' => 'User',
        'terminal_identifier' => 'Terminal Identifier',
        'session_id' => 'Session ID',
        'session_id_copied' => 'Session ID copied',
        'ssh_connection_details' => 'SSH Connection Details',
        'host' => 'Host',
        'port' => 'Port',
        'ssh_username' => 'SSH Username',
        'command' => 'Command',
        'command_copied' => 'Command copied',
        'exit_code' => 'Exit Code',
        'output' => 'Output',
        'client_information' => 'Client Information',
        'ip_address' => 'IP Address',
        'user_agent' => 'User Agent',
        'metadata' => 'Metadata',
        'metadata_key' => 'Key',
        'metadata_value' => 'Value',
    ],

    /*
    |--------------------------------------------------------------------------
    | Widgets
    |--------------------------------------------------------------------------
    */
    'widgets' => [
        'total_logs' => 'Total Logs',
        'all_terminal_log_entries' => 'All terminal log entries',
        'today' => 'Today',
        'logs_created_today' => 'Logs created today',
        'commands' => 'Commands',
        'total_commands_executed' => 'Total commands executed',
        'errors' => 'Errors',
        'total_error_events' => 'Total error events',
    ],
];
