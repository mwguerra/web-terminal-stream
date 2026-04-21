<?php

declare(strict_types=1);

use MWGuerra\WebTerminal\Data\ConnectionConfig;
use MWGuerra\WebTerminal\WebSocket\PtySessionRegistry;
use MWGuerra\WebTerminal\WebSocket\TerminalPtyBridge;

/*
 * Regression tests for GitHub issue #3 — SSH failures must not crash the
 * shared ReactPHP event loop. We can't easily stand up a real SSH server in
 * a unit test, so we inject a mock `$sshShell` via reflection and verify
 * that `read()` / `isRunning()` / `terminate()` stay well-behaved under
 * every throwable path phpseclib3 can surface.
 */

function injectSshMock(TerminalPtyBridge $bridge, object $mock): void
{
    $ref = new ReflectionClass($bridge);
    $prop = $ref->getProperty('sshShell');
    $prop->setAccessible(true);
    $prop->setValue($bridge, $mock);
}

function bridgeWithSshMock(object $mock): TerminalPtyBridge
{
    // We construct a local-type bridge and overwrite the sshShell property
    // — read()/isRunning()/terminate() dispatch on that property, so the
    // config type doesn't matter for these tests.
    $config = ConnectionConfig::local();
    $registry = new PtySessionRegistry(sys_get_temp_dir() . '/test-' . uniqid());
    $bridge = new TerminalPtyBridge($config, 'test-session', 1, $registry);
    injectSshMock($bridge, $mock);

    return $bridge;
}

it('returns empty output when SSH read() throws a TimeoutException', function () {
    $mock = new class {
        public function read(string $expect): string
        {
            throw new \phpseclib3\Exception\TimeoutException('timeout');
        }

        public function isConnected(): bool
        {
            return true;
        }
    };

    $bridge = bridgeWithSshMock($mock);

    expect($bridge->read())->toBe('');
});

it('marks the bridge dead when SSH read() throws a generic Throwable', function () {
    $mock = new class {
        public function read(string $expect): string
        {
            throw new \RuntimeException('channel broken');
        }

        public function isConnected(): bool
        {
            return true;
        }
    };

    $bridge = bridgeWithSshMock($mock);

    // First read swallows the exception and flips the dead flag.
    expect($bridge->read())->toBe('');

    // Subsequent read short-circuits without re-invoking the mock.
    expect($bridge->read())->toBe('');

    // isRunning() now reports false so ReactPhpWebSocketServer::tick() stops
    // polling and handleClose() fires promptly.
    expect($bridge->isRunning())->toBeFalse();
});

it('returns false from isRunning() if isConnected() throws', function () {
    $mock = new class {
        public function isConnected(): bool
        {
            throw new \RuntimeException('transport broken');
        }

        public function setTimeout(float|int $seconds): void {}

        public function disconnect(): void {}
    };

    $bridge = bridgeWithSshMock($mock);

    expect($bridge->isRunning())->toBeFalse();
});

it('terminate() does not throw when SSH disconnect() throws', function () {
    $mock = new class {
        public bool $setTimeoutCalled = false;

        public function setTimeout(float|int $seconds): void
        {
            $this->setTimeoutCalled = true;
        }

        public function disconnect(): void
        {
            throw new \RuntimeException('remote hung');
        }

        public function isConnected(): bool
        {
            return true;
        }
    };

    $bridge = bridgeWithSshMock($mock);

    // Must not bubble — terminate() is called from handleClose() in the
    // ReactPHP event loop and a throw here would kill every other session.
    expect(fn () => $bridge->terminate())->not->toThrow(\Throwable::class);
    expect($mock->setTimeoutCalled)->toBeTrue();
});

it('terminate() unregisters the session even when disconnect throws', function () {
    $registry = new PtySessionRegistry(sys_get_temp_dir() . '/test-' . uniqid());
    $config = ConnectionConfig::local();
    $bridge = new TerminalPtyBridge($config, 'test-session-unreg', 1, $registry);

    // Register so we can later assert cleanup.
    $registry->register('test-session-unreg', -1, 1);
    expect($registry->find('test-session-unreg'))->not->toBeNull();

    $mock = new class {
        public function setTimeout(float|int $seconds): void {}

        public function disconnect(): void
        {
            throw new \RuntimeException('hung');
        }

        public function isConnected(): bool
        {
            return true;
        }
    };

    injectSshMock($bridge, $mock);
    $bridge->terminate();

    expect($registry->find('test-session-unreg'))->toBeNull();
});
