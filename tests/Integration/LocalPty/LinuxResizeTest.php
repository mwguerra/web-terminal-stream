<?php

declare(strict_types=1);

use MWGuerra\WebTerminalStream\Data\ConnectionConfig;
use MWGuerra\WebTerminalStream\WebSocket\PtySessionRegistry;
use MWGuerra\WebTerminalStream\WebSocket\TerminalPtyBridge;

/*
 * The local-PTY resize path (/proc/<pid>/fd/0 readlink + stty -F + SIGWINCH)
 * only exists on Linux. On macOS run it inside the php-tests container:
 * composer test:integration:linux
 */

beforeEach(function () {
    if (PHP_OS_FAMILY !== 'Linux') {
        $this->markTestSkipped('Local PTY resize requires Linux (/proc + stty -F). Run: composer test:integration:linux');
    }
});

describe('local PTY resize on Linux', function () {
    it('resizes the PTY so stty reports the new dimensions', function () {
        $config = ConnectionConfig::local(timeout: 10);
        $registry = new PtySessionRegistry(sys_get_temp_dir().'/wts-linux-pty-'.uniqid());
        $bridge = new TerminalPtyBridge($config, 'linux-resize-session', 1, $registry);

        $bridge->start('/bin/bash');
        expect($bridge->isRunning())->toBeTrue();

        // Let the interactive shell finish booting before resizing its TTY.
        pollPtyOutput($bridge, '$', 5.0);

        $bridge->resize(120, 40);
        usleep(200_000); // let stty + SIGWINCH land
        $bridge->write("stty size\n");

        // `stty size` prints "rows cols".
        $output = pollPtyOutput($bridge, '40 120');

        $bridge->terminate();

        expect($output)->toContain('40 120');
    });
})->group('linux-pty');
