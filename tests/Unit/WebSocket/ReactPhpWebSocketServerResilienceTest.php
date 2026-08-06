<?php

declare(strict_types=1);

use Illuminate\Contracts\Encryption\Encrypter;
use MWGuerra\WebTerminalStream\WebSocket\PtySessionRegistry;
use MWGuerra\WebTerminalStream\WebSocket\ReactPhpWebSocketServer;
use MWGuerra\WebTerminalStream\WebSocket\TerminalPtyBridge;

/*
 * Resilience of the long-running WebSocket server: capacity caps, dead-session
 * reaping, lifetime reaping, and graceful shutdown. Uses reflection to inject
 * state (no real socket/loop), reusing nullConnection() from the loop-safety
 * test file — both are included in the same Pest process, so it is available
 * here without redeclaration.
 */

function resServer(array $config): ReactPhpWebSocketServer
{
    $encrypter = Mockery::mock(Encrypter::class);
    $registry = new PtySessionRegistry(sys_get_temp_dir().'/res-'.uniqid());

    return new ReactPhpWebSocketServer($registry, $encrypter, $config);
}

function resSet(ReactPhpWebSocketServer $server, string $prop, mixed $value): void
{
    $p = (new ReflectionClass($server))->getProperty($prop);
    $p->setAccessible(true);
    $p->setValue($server, $value);
}

function resGet(ReactPhpWebSocketServer $server, string $prop): mixed
{
    $p = (new ReflectionClass($server))->getProperty($prop);
    $p->setAccessible(true);

    return $p->getValue($server);
}

function resStubBridge(bool $running): TerminalPtyBridge
{
    return new class($running) extends TerminalPtyBridge
    {
        public bool $terminated = false;

        public function __construct(private bool $running)
        {
            // Test stand-in — skip parent constructor.
        }

        public function isRunning(): bool
        {
            return $this->running;
        }

        public function read(): string
        {
            return '';
        }

        public function terminate(): void
        {
            $this->terminated = true;
        }
    };
}

describe('capacityReason', function () {
    it('refuses when total connections reach max_connections', function () {
        $server = resServer(['max_connections' => 2, 'max_sessions_per_user' => 0]);
        resSet($server, 'bridges', [1 => resStubBridge(true), 2 => resStubBridge(true)]);

        expect($server->capacityReason(99))->toContain('at capacity');
    });

    it('admits when below max_connections', function () {
        $server = resServer(['max_connections' => 5, 'max_sessions_per_user' => 0]);
        resSet($server, 'bridges', [1 => resStubBridge(true)]);

        expect($server->capacityReason(99))->toBeNull();
    });

    it('refuses when a user reaches their per-user session limit', function () {
        $server = resServer(['max_connections' => 0, 'max_sessions_per_user' => 2]);
        resSet($server, 'bridges', [1 => resStubBridge(true), 2 => resStubBridge(true)]);
        resSet($server, 'userIds', [1 => 7, 2 => 7]);

        expect($server->capacityReason(7))->toContain('session limit')
            ->and($server->capacityReason(8))->toBeNull(); // a different user is fine
    });

    it('treats 0 limits as unlimited', function () {
        $server = resServer(['max_connections' => 0, 'max_sessions_per_user' => 0]);
        resSet($server, 'bridges', array_fill(0, 500, resStubBridge(true)));
        resSet($server, 'userIds', array_fill(0, 500, 7));

        expect($server->capacityReason(7))->toBeNull();
    });
});

describe('tick reaping', function () {
    it('closes and evicts a bridge that is no longer running', function () {
        $deadConn = nullConnection();
        $liveConn = nullConnection();

        $server = resServer([]);
        resSet($server, 'bridges', [1 => resStubBridge(false), 2 => resStubBridge(true)]);
        resSet($server, 'connections', [1 => $deadConn, 2 => $liveConn]);

        $server->tick();

        expect($deadConn->closed)->toBeTrue()
            ->and($liveConn->closed)->toBeFalse()
            ->and(resGet($server, 'bridges'))->toHaveKey(2)->not->toHaveKey(1);
    });
});

describe('reapExpired', function () {
    it('closes sessions older than the max lifetime and keeps fresh ones', function () {
        $oldConn = nullConnection();
        $newConn = nullConnection();

        $server = resServer([]);
        resSet($server, 'bridges', [1 => resStubBridge(true), 2 => resStubBridge(true)]);
        resSet($server, 'connections', [1 => $oldConn, 2 => $newConn]);
        resSet($server, 'startedAt', [1 => time() - 7200, 2 => time()]);

        $server->reapExpired(3600);

        expect($oldConn->closed)->toBeTrue()
            ->and($newConn->closed)->toBeFalse()
            ->and(resGet($server, 'bridges'))->toHaveKey(2)->not->toHaveKey(1);
    });

    it('is a no-op when the lifetime is 0', function () {
        $conn = nullConnection();
        $server = resServer([]);
        resSet($server, 'bridges', [1 => resStubBridge(true)]);
        resSet($server, 'connections', [1 => $conn]);
        resSet($server, 'startedAt', [1 => time() - 999999]);

        $server->reapExpired(0);

        expect($conn->closed)->toBeFalse();
    });
});

describe('shutdown', function () {
    it('terminates every bridge and clears all state', function () {
        $c1 = nullConnection();
        $c2 = nullConnection();
        $b1 = resStubBridge(true);
        $b2 = resStubBridge(true);

        $server = resServer([]);
        resSet($server, 'bridges', [1 => $b1, 2 => $b2]);
        resSet($server, 'connections', [1 => $c1, 2 => $c2]);
        resSet($server, 'userIds', [1 => 5, 2 => 6]);
        resSet($server, 'startedAt', [1 => time(), 2 => time()]);

        $server->shutdown();

        expect($b1->terminated)->toBeTrue()
            ->and($b2->terminated)->toBeTrue()
            ->and($c1->closed)->toBeTrue()
            ->and($c2->closed)->toBeTrue()
            ->and(resGet($server, 'bridges'))->toBe([])
            ->and($server->activeConnectionCount())->toBe(0);
    });
});
