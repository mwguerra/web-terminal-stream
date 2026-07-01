<?php

declare(strict_types=1);

use Illuminate\Contracts\Encryption\Encrypter;
use MWGuerra\WebTerminalStream\WebSocket\PtySessionRegistry;
use MWGuerra\WebTerminalStream\WebSocket\ReactPhpWebSocketServer;
use React\Socket\ConnectionInterface;
use React\Stream\WritableStreamInterface;

/*
 * Handshake-level Origin enforcement tests.
 *
 * The contract: an Origin outside `stream.allowed_origins` is rejected with
 * an HTTP 403 *before* the single-use token is consumed (the encrypter is
 * never asked to decrypt it), while allowed or absent Origins proceed to
 * token validation as before.
 */

/**
 * A fake ReactPHP connection that records writes/closes and lets the test
 * emit `data` events into the server's handshake path.
 */
class OriginTestConnection implements ConnectionInterface
{
    /** @var array<int, string> */
    public array $writes = [];

    public bool $closed = false;

    /** @var array<string, array<int, callable>> */
    private array $listeners = [];

    public function emitData(string $data): void
    {
        foreach ($this->listeners['data'] ?? [] as $listener) {
            $listener($data);
        }
    }

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

    public function on($event, callable $listener): void
    {
        $this->listeners[$event][] = $listener;
    }

    public function once($event, callable $listener): void
    {
        $this->listeners[$event][] = $listener;
    }

    public function removeListener($event, callable $listener): void {}

    public function removeAllListeners($event = null): void {}

    public function listeners($event = null): array
    {
        return $this->listeners[$event] ?? [];
    }

    public function emit($event, array $arguments = []): void {}

    public function eventNames(): array
    {
        return array_keys($this->listeners);
    }
}

function originTestServer(Encrypter $encrypter, array $config): ReactPhpWebSocketServer
{
    $registry = new PtySessionRegistry(sys_get_temp_dir().'/test-origin-'.uniqid());

    return new ReactPhpWebSocketServer($registry, $encrypter, $config);
}

function wsUpgradeRequest(?string $origin = null): string
{
    $request = "GET /?token=some-token HTTP/1.1\r\n"
        ."Host: 127.0.0.1:8090\r\n"
        ."Upgrade: websocket\r\n"
        ."Connection: Upgrade\r\n"
        ."Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\n"
        ."Sec-WebSocket-Version: 13\r\n";

    if ($origin !== null) {
        $request .= "Origin: {$origin}\r\n";
    }

    return $request."\r\n";
}

it('rejects a disallowed Origin with 403 before consuming the token', function () {
    $encrypter = Mockery::mock(Encrypter::class);
    $encrypter->shouldNotReceive('decrypt');

    $server = originTestServer($encrypter, ['allowed_origins' => ['https://app.test']]);

    $conn = new OriginTestConnection;
    $server->handleConnection($conn);
    $conn->emitData(wsUpgradeRequest('https://evil.test'));

    expect($conn->closed)->toBeTrue();
    expect(implode('', $conn->writes))->toContain('403 Forbidden');
});

it('lets an allowed Origin through to token validation', function () {
    $encrypter = Mockery::mock(Encrypter::class);
    $encrypter->shouldReceive('decrypt')
        ->once()
        ->with('some-token')
        ->andThrow(new Exception('invalid payload'));

    $server = originTestServer($encrypter, ['allowed_origins' => ['https://app.test']]);

    $conn = new OriginTestConnection;
    $server->handleConnection($conn);
    $conn->emitData(wsUpgradeRequest('https://app.test'));

    // The connection is closed by the (deliberately) failing token check,
    // not by the Origin gate — no 403 was written.
    expect($conn->closed)->toBeTrue();
    expect(implode('', $conn->writes))->not->toContain('403 Forbidden');
});

it('lets a request without an Origin header through to token validation', function () {
    $encrypter = Mockery::mock(Encrypter::class);
    $encrypter->shouldReceive('decrypt')
        ->once()
        ->andThrow(new Exception('invalid payload'));

    $server = originTestServer($encrypter, ['allowed_origins' => ['https://app.test']]);

    $conn = new OriginTestConnection;
    $server->handleConnection($conn);
    $conn->emitData(wsUpgradeRequest());

    expect(implode('', $conn->writes))->not->toContain('403 Forbidden');
});

it('disables the check when allowed_origins contains a literal wildcard', function () {
    $encrypter = Mockery::mock(Encrypter::class);
    $encrypter->shouldReceive('decrypt')
        ->once()
        ->andThrow(new Exception('invalid payload'));

    $server = originTestServer($encrypter, ['allowed_origins' => ['*']]);

    $conn = new OriginTestConnection;
    $server->handleConnection($conn);
    $conn->emitData(wsUpgradeRequest('https://anything.example'));

    expect(implode('', $conn->writes))->not->toContain('403 Forbidden');
});
