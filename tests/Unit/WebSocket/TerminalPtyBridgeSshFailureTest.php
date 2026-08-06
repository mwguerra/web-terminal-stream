<?php

declare(strict_types=1);

use MWGuerra\WebTerminalStream\Data\ConnectionConfig;
use MWGuerra\WebTerminalStream\WebSocket\PtySessionRegistry;
use MWGuerra\WebTerminalStream\WebSocket\TerminalPtyBridge;

/*
 * The bridge is intentionally truthful: if the underlying transport
 * throws, the bridge lets it propagate. Event-loop safety lives in
 * ReactPhpWebSocketServer::tick / handleMessage, which catch at the
 * boundary and close the offending session. See:
 * tests/Unit/WebSocket/ReactPhpWebSocketServerLoopSafetyTest.php
 *
 * These bridge-level tests pin the truthful behavior: a null SSH
 * shell must look like "not running" and return empty on read.
 */

function sshlessBridge(): TerminalPtyBridge
{
    $config = ConnectionConfig::local();
    $registry = new PtySessionRegistry(sys_get_temp_dir().'/test-'.uniqid());

    return new TerminalPtyBridge($config, 'test-session', 1, $registry);
}

it('reports not running when no SSH shell and no local process is attached', function () {
    expect(sshlessBridge()->isRunning())->toBeFalse();
});

it('returns empty string from read when no SSH shell and no local process is attached', function () {
    expect(sshlessBridge()->read())->toBe('');
});
