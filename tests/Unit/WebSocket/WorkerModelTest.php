<?php

declare(strict_types=1);

use MWGuerra\WebTerminalStream\Console\Commands\TerminalServeCommand;
use MWGuerra\WebTerminalStream\WebSocket\PtySessionRegistry;
use MWGuerra\WebTerminalStream\WebSocket\ReactPhpProvider;

/*
 * The worker model (1.1.1).
 *
 * A session's SSH connect + auth runs synchronously on its server's event
 * loop, so every session on that loop stalls while another connects. Running
 * N servers behind SO_REUSEPORT does not make connect asynchronous — it makes
 * the stall hit 1/N of the fleet instead of all of it.
 *
 * The fork itself is exercised by hand and in the stress harness; what is
 * pinned here is the plumbing around it, and the file-locking that only
 * becomes load-bearing once more than one process writes the registry.
 */

describe('worker model', function () {
    it('defaults to a single process, so upgrading changes nothing', function () {
        $config = require __DIR__.'/../../../config/web-terminal-stream.php';

        expect($config['stream']['workers'])->toBe(1);
    });

    it('offers a --workers option on the serve command', function () {
        $signature = (new ReflectionClass(TerminalServeCommand::class))
            ->getDefaultProperties()['signature'] ?? '';

        expect($signature)->toContain('--workers');
    });

    it('lets the provider bind with SO_REUSEPORT', function () {
        // Without this the second worker cannot bind and the fleet silently
        // collapses to one.
        $method = new ReflectionMethod(ReactPhpProvider::class, 'start');
        $names = array_map(fn ($p) => $p->getName(), $method->getParameters());

        expect($names)->toContain('reusePort');
    });
});

describe('registry under concurrent workers', function () {
    it('loses no session when several processes register at once', function () {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('needs ext-pcntl');
        }

        $dir = sys_get_temp_dir().'/wts-concurrency-'.uniqid();
        mkdir($dir, 0755, true);

        $writers = 8;
        $perWriter = 12;

        // Each child registers its own batch. With the old read-modify-write
        // (LOCK_EX on the WRITE only) two children could both read the same
        // state and the later write would drop the earlier one's entries.
        for ($w = 0; $w < $writers; $w++) {
            $pid = pcntl_fork();

            if ($pid === 0) {
                $registry = new PtySessionRegistry($dir);

                for ($i = 0; $i < $perWriter; $i++) {
                    $registry->register("w{$w}-s{$i}", -1, $w);
                }

                exit(0);
            }
        }

        while (pcntl_wait($status) > 0) {
            // drain
        }

        $all = (new PtySessionRegistry($dir))->all();

        expect($all)->toHaveCount($writers * $perWriter);

        // Spot-check that entries from the first and last writer both survived,
        // which is what a lost-update would take out.
        expect($all)->toHaveKey('w0-s0')
            ->and($all)->toHaveKey('w'.($writers - 1).'-s'.($perWriter - 1));

        array_map('unlink', glob($dir.'/*') ?: []);
        rmdir($dir);
    });

    it('still reads back what a single process wrote', function () {
        $dir = sys_get_temp_dir().'/wts-single-'.uniqid();
        $registry = new PtySessionRegistry($dir);

        $registry->register('only', 4242, 7);

        expect($registry->find('only'))->not->toBeNull()
            ->and($registry->find('only')['pid'])->toBe(4242)
            ->and($registry->find('only')['userId'])->toBe(7);

        $registry->unregister('only');
        expect($registry->find('only'))->toBeNull();

        array_map('unlink', glob($dir.'/*') ?: []);
        rmdir($dir);
    });
});
