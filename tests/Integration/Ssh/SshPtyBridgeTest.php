<?php

declare(strict_types=1);

use MWGuerra\WebTerminalStream\Data\ConnectionConfig;
use MWGuerra\WebTerminalStream\WebSocket\PtySessionRegistry;
use MWGuerra\WebTerminalStream\WebSocket\TerminalPtyBridge;

beforeEach(function () {
    requireSshTarget();
});

afterEach(function () {
    if (isset($this->bridge)) {
        try {
            $this->bridge->terminate();
        } catch (Throwable) {
            // Already terminated / never started.
        }
    }
});

function makeSshBridge(ConnectionConfig $config): TerminalPtyBridge
{
    $registry = new PtySessionRegistry(sys_get_temp_dir().'/wts-integration-'.uniqid());

    return new TerminalPtyBridge($config, 'integration-session-'.uniqid(), 1, $registry);
}

describe('SSH PTY bridge against the docker sshd container', function () {
    it('authenticates with a password and round-trips an echo', function () {
        $ssh = sshTestConfig();
        $config = ConnectionConfig::sshWithPassword(
            host: $ssh['host'],
            username: $ssh['username'],
            password: $ssh['password'],
            port: $ssh['port'],
            timeout: 10,
        );

        $this->bridge = makeSshBridge($config);
        $this->bridge->start();

        expect($this->bridge->isRunning())->toBeTrue();

        // $((...)) expands remotely, so the typed line never contains the
        // expected marker — only real command output can match.
        $this->bridge->write("echo wts-pw-$((1000+337))\n");

        expect(pollPtyOutput($this->bridge, 'wts-pw-1337'))->toContain('wts-pw-1337');
    });

    it('authenticates with the committed private key', function () {
        $ssh = sshTestConfig();
        $config = ConnectionConfig::sshWithKey(
            host: $ssh['host'],
            username: $ssh['username'],
            privateKey: file_get_contents($ssh['key_path']),
            port: $ssh['port'],
            timeout: 10,
        );

        $this->bridge = makeSshBridge($config);
        $this->bridge->start();

        expect($this->bridge->isRunning())->toBeTrue();

        $this->bridge->write("echo wts-key-$((2000+448))\n");

        expect(pollPtyOutput($this->bridge, 'wts-key-2448'))->toContain('wts-key-2448');
    });

    it('authenticates with a passphrase-protected private key', function () {
        $ssh = sshTestConfig();
        $config = ConnectionConfig::sshWithKey(
            host: $ssh['host'],
            username: $ssh['username'],
            privateKey: file_get_contents($ssh['key_pw_path']),
            passphrase: $ssh['key_passphrase'],
            port: $ssh['port'],
            timeout: 10,
        );

        $this->bridge = makeSshBridge($config);
        $this->bridge->start();

        expect($this->bridge->isRunning())->toBeTrue();

        $this->bridge->write("echo wts-kpw-$((3000+559))\n");

        expect(pollPtyOutput($this->bridge, 'wts-kpw-3559'))->toContain('wts-kpw-3559');
    });

    it('propagates resize to the remote PTY', function () {
        $ssh = sshTestConfig();
        $config = ConnectionConfig::sshWithPassword(
            host: $ssh['host'],
            username: $ssh['username'],
            password: $ssh['password'],
            port: $ssh['port'],
            timeout: 10,
        );

        $this->bridge = makeSshBridge($config);
        $this->bridge->start();

        $this->bridge->resize(120, 40);
        $this->bridge->write("stty size\n");

        // `stty size` prints "rows cols".
        expect(pollPtyOutput($this->bridge, '40 120'))->toContain('40 120');
    });

    it('reports not running after terminate', function () {
        $ssh = sshTestConfig();
        $config = ConnectionConfig::sshWithPassword(
            host: $ssh['host'],
            username: $ssh['username'],
            password: $ssh['password'],
            port: $ssh['port'],
            timeout: 10,
        );

        $this->bridge = makeSshBridge($config);
        $this->bridge->start();
        expect($this->bridge->isRunning())->toBeTrue();

        $this->bridge->terminate();

        expect($this->bridge->isRunning())->toBeFalse();
    });

    it('throws on a wrong password', function () {
        $ssh = sshTestConfig();
        $config = ConnectionConfig::sshWithPassword(
            host: $ssh['host'],
            username: $ssh['username'],
            password: 'definitely-not-the-password',
            port: $ssh['port'],
            timeout: 10,
        );

        $bridge = makeSshBridge($config);

        expect(fn () => $bridge->start())
            ->toThrow(RuntimeException::class, 'SSH password authentication failed');
    });
});
