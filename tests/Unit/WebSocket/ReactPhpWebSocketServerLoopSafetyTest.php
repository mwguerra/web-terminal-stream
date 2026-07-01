<?php

declare(strict_types=1);

use Illuminate\Contracts\Encryption\Encrypter;
use MWGuerra\WebTerminalStream\WebSocket\PtySessionRegistry;
use MWGuerra\WebTerminalStream\WebSocket\ReactPhpWebSocketServer;
use MWGuerra\WebTerminalStream\WebSocket\TerminalPtyBridge;
use React\Socket\ConnectionInterface;
use React\Stream\WritableStreamInterface;

/*
 * Event-loop safety boundary tests.
 *
 * The contract is: if any bridge operation throws during tick() or
 * handleMessage(), the shared event loop must stay alive and the
 * offending session must be closed cleanly so every other active
 * session keeps working.
 *
 * We don't need a real ReactPHP loop or network socket — we inject
 * a throwing bridge and a null-object connection, then invoke the
 * public entry points and assert no throw escapes.
 */

function serverWithInjectedBridges(array $bridges, array $connections = []): ReactPhpWebSocketServer
{
    $encrypter = Mockery::mock(Encrypter::class);
    $registry = new PtySessionRegistry(sys_get_temp_dir().'/test-'.uniqid());
    $server = new ReactPhpWebSocketServer($registry, $encrypter, []);

    $ref = new ReflectionClass($server);

    $bridgesProp = $ref->getProperty('bridges');
    $bridgesProp->setAccessible(true);
    $bridgesProp->setValue($server, $bridges);

    $connsProp = $ref->getProperty('connections');
    $connsProp->setAccessible(true);
    $connsProp->setValue($server, $connections);

    return $server;
}

function throwingBridge(string $on = 'isRunning'): TerminalPtyBridge
{
    return new class($on) extends TerminalPtyBridge
    {
        public function __construct(private string $throwOn)
        {
            // Intentionally skip parent constructor — we're a test stand-in.
        }

        public function isRunning(): bool
        {
            if ($this->throwOn === 'isRunning') {
                throw new RuntimeException('transport gone');
            }

            return true;
        }

        public function read(): string
        {
            if ($this->throwOn === 'read') {
                throw new RuntimeException('channel broken');
            }

            return '';
        }

        public function write(string $data): void
        {
            if ($this->throwOn === 'write') {
                throw new RuntimeException('write failed');
            }
        }

        public function resize(int $cols, int $rows): void
        {
            if ($this->throwOn === 'resize') {
                throw new RuntimeException('resize failed');
            }
        }

        public function terminate(): void
        {
            if ($this->throwOn === 'terminate') {
                throw new RuntimeException('terminate failed');
            }
        }
    };
}

function nullConnection(): ConnectionInterface
{
    return new class implements ConnectionInterface
    {
        public array $writes = [];

        public bool $closed = false;

        public function getRemoteAddress(): ?string
        {
            return '127.0.0.1:0';
        }

        public function getLocalAddress(): ?string
        {
            return '127.0.0.1:0';
        }

        public function isReadable(): bool
        {
            return ! $this->closed;
        }

        public function isWritable(): bool
        {
            return ! $this->closed;
        }

        public function pause(): void {}

        public function resume(): void {}

        public function pipe(WritableStreamInterface $dest, array $options = []): WritableStreamInterface
        {
            return $dest;
        }

        public function write($data): bool
        {
            $this->writes[] = (string) $data;

            return true;
        }

        public function end($data = null): void
        {
            $this->closed = true;
        }

        public function close(): void
        {
            $this->closed = true;
        }

        public function on($event, callable $listener): void {}

        public function once($event, callable $listener): void {}

        public function removeListener($event, callable $listener): void {}

        public function removeAllListeners($event = null): void {}

        public function listeners($event = null): array
        {
            return [];
        }

        public function emit($event, array $arguments = []): void {}

        public function eventNames(): array
        {
            return [];
        }
    };
}

it('tick() does not throw when a bridge isRunning() throws', function () {
    $server = serverWithInjectedBridges([
        1 => throwingBridge(on: 'isRunning'),
    ], [
        1 => nullConnection(),
    ]);

    expect(fn () => $server->tick())->not->toThrow(Throwable::class);
});

it('tick() does not throw when a bridge read() throws', function () {
    $server = serverWithInjectedBridges([
        1 => throwingBridge(on: 'read'),
    ], [
        1 => nullConnection(),
    ]);

    expect(fn () => $server->tick())->not->toThrow(Throwable::class);
});

it('tick() closes the connection of a bridge that throws and preserves the others', function () {
    $badConn = nullConnection();
    $goodConn = nullConnection();

    $server = serverWithInjectedBridges([
        1 => throwingBridge(on: 'read'),
        2 => throwingBridge(on: 'none'),
    ], [
        1 => $badConn,
        2 => $goodConn,
    ]);

    $server->tick();

    expect($badConn->closed)->toBeTrue();
    expect($goodConn->closed)->toBeFalse();

    // Offending session is evicted from the registry.
    $ref = new ReflectionClass($server);
    $bridgesProp = $ref->getProperty('bridges');
    $bridgesProp->setAccessible(true);

    expect($bridgesProp->getValue($server))->toHaveKey(2)->not->toHaveKey(1);
});

it('handleMessage() does not throw when a bridge write() throws', function () {
    $server = serverWithInjectedBridges([
        1 => throwingBridge(on: 'write'),
    ], [
        1 => nullConnection(),
    ]);

    $ref = new ReflectionClass($server);
    $method = $ref->getMethod('handleMessage');
    $method->setAccessible(true);

    expect(fn () => $method->invoke($server, 1, 'echo hello'))->not->toThrow(Throwable::class);
});

it('handleMessage() does not throw when a resize dispatch throws', function () {
    $server = serverWithInjectedBridges([
        1 => throwingBridge(on: 'resize'),
    ], [
        1 => nullConnection(),
    ]);

    $ref = new ReflectionClass($server);
    $method = $ref->getMethod('handleMessage');
    $method->setAccessible(true);

    expect(fn () => $method->invoke($server, 1, json_encode(['type' => 'resize', 'cols' => 80, 'rows' => 24])))
        ->not->toThrow(Throwable::class);
});

it('handleClose() swallows throws from a bridge terminate() path', function () {
    $server = serverWithInjectedBridges([
        1 => throwingBridge(on: 'terminate'),
    ]);

    $ref = new ReflectionClass($server);
    $method = $ref->getMethod('handleClose');
    $method->setAccessible(true);

    expect(fn () => $method->invoke($server, 1))->not->toThrow(Throwable::class);
});
