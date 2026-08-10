<?php

declare(strict_types=1);

use Illuminate\Contracts\Encryption\Encrypter;
use MWGuerra\WebTerminalStream\WebSocket\PtySessionRegistry;
use MWGuerra\WebTerminalStream\WebSocket\ReactPhpWebSocketServer;
use MWGuerra\WebTerminalStream\WebSocket\TerminalPtyBridge;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Socket\ConnectionInterface;

/*
 * Event-driven PTY output.
 *
 * Before 1.1.1 the server asked every session, every 10ms, whether it had
 * output — and each SSH read waited out its own timeout even when the answer
 * was "no". Round-trip latency therefore scaled with the NUMBER OF OPEN
 * TERMINALS rather than with load: measured 1877ms p50 at 100 sessions, versus
 * 264ms after this change on the same machine.
 *
 * These tests pin the behaviours that make that safe, since each of them is a
 * silent-output bug if it regresses.
 */

/** A loop that records what was registered, without running anything. */
function recordingLoop(): LoopInterface
{
    return new class implements LoopInterface
    {
        /** @var list<mixed> */
        public array $added = [];

        /** @var list<mixed> */
        public array $removed = [];

        public function addReadStream($stream, $listener): void
        {
            $this->added[] = $stream;
        }

        public function removeReadStream($stream): void
        {
            $this->removed[] = $stream;
        }

        public function addWriteStream($stream, $listener): void {}

        public function removeWriteStream($stream): void {}

        public function addTimer($interval, $callback)
        {
            return new class implements TimerInterface
            {
                public function getInterval(): float
                {
                    return 0.0;
                }

                public function getCallback(): callable
                {
                    return static fn () => null;
                }

                public function isPeriodic(): bool
                {
                    return false;
                }
            };
        }

        public function addPeriodicTimer($interval, $callback)
        {
            return $this->addTimer($interval, $callback);
        }

        public function cancelTimer(TimerInterface $timer): void {}

        public function futureTick($listener): void {}

        public function addSignal($signal, $listener): void {}

        public function removeSignal($signal, $listener): void {}

        public function run(): void {}

        public function stop(): void {}
    };
}

/**
 * A bridge that reports fixed streams and hands back a scripted output
 * sequence, one `read()` at a time — exactly how phpseclib behaves.
 */
function scriptedBridge(array $streams, array $outputs = []): TerminalPtyBridge
{
    return new class($streams, $outputs) extends TerminalPtyBridge
    {
        public int $reads = 0;

        public bool $switchedToEventReads = false;

        public function __construct(private array $streams, private array $outputs) {}

        public function readableStreams(): array
        {
            return $this->streams;
        }

        public function useEventDrivenReads(): void
        {
            $this->switchedToEventReads = true;
        }

        public function isRunning(): bool
        {
            return true;
        }

        public function read(): string
        {
            $this->reads++;

            return array_shift($this->outputs) ?? '';
        }

        public function write(string $data): void {}

        public function resize(int $cols, int $rows): void {}

        public function terminate(): void {}
    };
}

function serverWith(array $config, array $bridges = [], array $connections = []): ReactPhpWebSocketServer
{
    $server = new ReactPhpWebSocketServer(
        new PtySessionRegistry(sys_get_temp_dir().'/wts-'.uniqid()),
        Mockery::mock(Encrypter::class),
        $config,
    );

    $ref = new ReflectionClass($server);

    foreach (['bridges' => $bridges, 'connections' => $connections] as $name => $value) {
        $prop = $ref->getProperty($name);
        $prop->setAccessible(true);
        $prop->setValue($server, $value);
    }

    return $server;
}

function callPrivate(object $object, string $method, array $args = []): mixed
{
    $ref = new ReflectionMethod($object, $method);
    $ref->setAccessible(true);

    return $ref->invokeArgs($object, $args);
}

/** A pair of real stream resources to stand in for a transport. */
function streamPair(): array
{
    return stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
}

describe('event-driven PTY output', function () {
    it('is the default mode, with polling reachable as an escape hatch', function () {
        $config = require __DIR__.'/../../../config/web-terminal-stream.php';

        expect($config['stream']['io_mode'])->toBe('event');
    });

    it('only counts as event-driven once a loop is attached', function () {
        $server = serverWith([]);

        // No loop yet: the server must not claim a capability it cannot use.
        expect($server->isEventDriven())->toBeFalse();

        $server->attachLoop(recordingLoop(), 'event');
        expect($server->isEventDriven())->toBeTrue();
    });

    it('honours poll mode even with a loop attached', function () {
        $server = serverWith([]);
        $server->attachLoop(recordingLoop(), 'poll');

        expect($server->isEventDriven())->toBeFalse();
    });

    it('treats an unknown mode as event rather than silently polling', function () {
        $server = serverWith([]);
        $server->attachLoop(recordingLoop(), 'nonsense');

        expect($server->isEventDriven())->toBeTrue();
    });

    it('registers every one of a session\'s transports with the loop', function () {
        [$a, $b] = streamPair();
        $loop = recordingLoop();
        $bridge = scriptedBridge([$a, $b]);

        $server = serverWith([], [7 => $bridge]);
        $server->attachLoop($loop, 'event');

        callPrivate($server, 'watchBridge', [7]);

        // A local PTY exposes stdout AND stderr; watching only one loses half
        // the output.
        expect($loop->added)->toHaveCount(2)
            ->and($bridge->switchedToEventReads)->toBeTrue();
    });

    it('registers nothing in poll mode', function () {
        [$a] = streamPair();
        $loop = recordingLoop();
        $bridge = scriptedBridge([$a]);

        $server = serverWith([], [7 => $bridge]);
        $server->attachLoop($loop, 'poll');

        callPrivate($server, 'watchBridge', [7]);

        expect($loop->added)->toBeEmpty()
            ->and($bridge->switchedToEventReads)->toBeFalse();
    });

    it('deregisters the streams when the session closes', function () {
        [$a, $b] = streamPair();
        $loop = recordingLoop();

        $server = serverWith([], [7 => scriptedBridge([$a, $b])]);
        $server->attachLoop($loop, 'event');
        callPrivate($server, 'watchBridge', [7]);

        callPrivate($server, 'unwatchBridge', [7]);

        // Left registered, the loop keeps waking on a descriptor that is about
        // to be closed — a busy spin for the rest of the process's life.
        expect($loop->removed)->toHaveCount(2);
    });

    it('drains everything buffered instead of stopping after the first read', function () {
        // One readable notification can cover many buffered packets, and
        // phpseclib hands them over one read() at a time. Stopping at the first
        // strands the rest until the next wakeup.
        $bridge = scriptedBridge([], ['one', 'two', 'three']);
        $written = [];

        $conn = Mockery::mock(ConnectionInterface::class);
        $conn->shouldReceive('write')->andReturnUsing(function ($frame) use (&$written) {
            $written[] = $frame;

            return true;
        });

        $server = serverWith([], [7 => $bridge], [7 => $conn]);
        callPrivate($server, 'pumpSession', [7]);

        expect($written)->toHaveCount(3);
    });

    it('stops draining at a bound so one loud session cannot starve the others', function () {
        // A session emitting without pause (`yes`, a huge `cat`) must yield the
        // loop back rather than hold it for as long as it keeps talking.
        $bridge = scriptedBridge([], array_fill(0, 500, 'noise'));

        $conn = Mockery::mock(ConnectionInterface::class);
        $conn->shouldReceive('write')->andReturn(true);

        $server = serverWith([], [7 => $bridge], [7 => $conn]);
        callPrivate($server, 'pumpSession', [7]);

        expect($bridge->reads)->toBeLessThanOrEqual(64)
            ->and($bridge->reads)->toBeGreaterThan(1);
    });

    it('survives being pumped for a session that no longer exists', function () {
        $server = serverWith([]);

        // A queued wakeup can land after the session was torn down.
        expect(fn () => callPrivate($server, 'pumpSession', [999]))->not->toThrow(Throwable::class);
    });
});
