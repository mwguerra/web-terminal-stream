<?php

declare(strict_types=1);

namespace MWGuerra\WebTerminalStream\WebSocket;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Message;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use MWGuerra\WebTerminalStream\Data\ConnectionConfig;
use MWGuerra\WebTerminalStream\Metrics\ServerMetrics;
use MWGuerra\WebTerminalStream\Security\ConnectionPolicy;
use MWGuerra\WebTerminalStream\Services\TerminalLogger;
use Ratchet\RFC6455\Handshake\RequestVerifier;
use Ratchet\RFC6455\Handshake\ServerNegotiator;
use Ratchet\RFC6455\Messaging\CloseFrameChecker;
use Ratchet\RFC6455\Messaging\Frame;
use Ratchet\RFC6455\Messaging\MessageBuffer;
use React\EventLoop\LoopInterface;
use React\Socket\ConnectionInterface;

class ReactPhpWebSocketServer
{
    /** @var array<int, TerminalPtyBridge> Maps connection object ID to PTY bridge */
    private array $bridges = [];

    /** @var array<int, ConnectionInterface> Maps connection object ID to connection */
    private array $connections = [];

    /** @var array<int, MessageBuffer> Maps connection object ID to message buffer */
    private array $buffers = [];

    /** @var array<int, int|string|null> Maps connection object ID to the owning user id (as the application keys it) */
    private array $userIds = [];

    /** @var array<int, int> Maps connection object ID to its start timestamp */
    private array $startedAt = [];

    /** @var array<int, string> Maps connection object ID to its terminal session id */
    private array $sessionIds = [];

    /** @var array<int, string> Maps connection object ID to its connection type */
    private array $connectionTypes = [];

    private int $maxConnections;

    private int $maxSessionsPerUser;

    private int $maxHandshakeBytes;

    private PtySessionRegistry $registry;

    private Encrypter $encrypter;

    private array $config;

    private ServerNegotiator $negotiator;

    private OriginValidator $originValidator;

    private ?TerminalLogger $logger;

    private ?LoopInterface $loop = null;

    private ?ServerMetrics $metrics = null;

    /** 'event' = the loop wakes us on readable transports; 'poll' = the legacy timer sweep. */
    private string $ioMode = 'poll';

    /** @var array<int, list<resource>> connection id => streams registered with the loop */
    private array $watched = [];

    public function __construct(
        PtySessionRegistry $registry,
        Encrypter $encrypter,
        array $config,
        ?TerminalLogger $logger = null,
    ) {
        $this->registry = $registry;
        $this->encrypter = $encrypter;
        $this->config = $config;
        $this->logger = $logger;
        $this->maxConnections = (int) ($config['max_connections'] ?? 100);
        $this->maxSessionsPerUser = (int) ($config['max_sessions_per_user'] ?? 10);
        $this->maxHandshakeBytes = (int) ($config['max_handshake_bytes'] ?? 16384);
        $this->negotiator = new ServerNegotiator(
            new RequestVerifier,
            new HttpFactory,
        );
        $this->originValidator = new OriginValidator($config['allowed_origins'] ?? []);
    }

    /**
     * Hand the server the event loop so PTY output can be event-driven.
     *
     * Without this the server falls back to polling every session on a timer —
     * the historical behaviour, kept reachable through `stream.io_mode=poll`
     * so a host that hits trouble can revert without downgrading the package.
     */
    public function attachLoop(LoopInterface $loop, string $ioMode = 'event'): void
    {
        $this->loop = $loop;
        $this->ioMode = $ioMode === 'poll' ? 'poll' : 'event';
    }

    public function isEventDriven(): bool
    {
        return $this->ioMode === 'event' && $this->loop !== null;
    }

    public function attachMetrics(ServerMetrics $metrics): void
    {
        $this->metrics = $metrics;
    }

    /** Live PTY count for this worker — what the published snapshot reports. */
    public function liveSessions(): int
    {
        return count($this->bridges);
    }

    /**
     * Ask the loop to wake us when this session's transport has bytes, instead
     * of asking the session every few milliseconds whether it does.
     */
    private function watchBridge(int $id): void
    {
        if (! $this->isEventDriven()) {
            return;
        }

        $bridge = $this->bridges[$id] ?? null;

        if ($bridge === null) {
            return;
        }

        $streams = $bridge->readableStreams();

        if ($streams === []) {
            // Nothing watchable (shouldn't happen) — the backstop sweep still
            // covers this session, so it degrades to slow rather than silent.
            return;
        }

        $bridge->useEventDrivenReads();

        foreach ($streams as $stream) {
            $this->loop->addReadStream($stream, function () use ($id): void {
                $this->pumpSession($id);
            });
        }

        $this->watched[$id] = $streams;
    }

    private function unwatchBridge(int $id): void
    {
        foreach ($this->watched[$id] ?? [] as $stream) {
            if (is_resource($stream)) {
                $this->loop?->removeReadStream($stream);
            }
        }

        unset($this->watched[$id]);
    }

    public function handleConnection(ConnectionInterface $conn): void
    {
        $id = spl_object_id($conn);
        $httpBuffer = '';

        $conn->on('data', function (string $data) use ($conn, $id, &$httpBuffer) {
            // If we haven't completed the handshake yet
            if (! isset($this->buffers[$id])) {
                $httpBuffer .= $data;

                // Bound the pre-handshake buffer: a client that opens a socket
                // and never sends the terminating CRLF must not grow memory
                // without limit (a trivial DoS otherwise).
                if ($this->maxHandshakeBytes > 0 && strlen($httpBuffer) > $this->maxHandshakeBytes) {
                    Log::warning('[web-terminal-stream] Rejected handshake: request exceeded max_handshake_bytes');
                    $conn->close();

                    return;
                }

                $this->handleHandshake($conn, $id, $httpBuffer);

                return;
            }

            $this->buffers[$id]->onData($data);
        });

        $conn->on('close', function () use ($id) {
            $this->handleClose($id);
        });

        $conn->on('error', function (\Exception $e) use ($conn, $id) {
            $conn->close();
            $this->handleClose($id);
        });
    }

    private function handleHandshake(ConnectionInterface $conn, int $id, string $httpBuffer): void
    {
        // Only try to parse once we have a full HTTP request (ends with double CRLF)
        if (strpos($httpBuffer, "\r\n\r\n") === false) {
            return;
        }

        try {
            $request = Message::parseRequest($httpBuffer);
        } catch (\Throwable $e) {
            $conn->close();

            return;
        }

        // Negotiate WebSocket upgrade
        $response = $this->negotiator->handshake($request);

        if ($response->getStatusCode() !== 101) {
            $conn->write(Message::toString($response));
            $conn->close();

            return;
        }

        // Enforce the Origin allow-list before the single-use token is
        // consumed — a rejected page must not burn the token it stole.
        // Browsers always send Origin on WebSocket upgrades; requests
        // without one (non-browser clients) pass through to token auth.
        $origin = $request->getHeaderLine('Origin');

        if (! $this->originValidator->allows($origin !== '' ? $origin : null)) {
            Log::warning('[web-terminal-stream] Rejected WebSocket handshake: Origin is not in stream.allowed_origins', [
                'origin' => $origin,
            ]);

            $conn->write("HTTP/1.1 403 Forbidden\r\nConnection: close\r\nContent-Length: 0\r\n\r\n");
            $conn->close();

            return;
        }

        // Extract and validate token
        $query = $request->getUri()->getQuery();
        parse_str($query, $params);
        $token = $params['token'] ?? null;

        if (! $token) {
            $conn->close();

            return;
        }

        try {
            $payload = json_decode($this->encrypter->decrypt($token), true);
        } catch (\Exception $e) {
            $conn->close();

            return;
        }

        if (! $payload || ($payload['exp'] ?? 0) < time()) {
            $conn->close();

            return;
        }

        $sessionId = $payload['sessionId'] ?? null;
        $userId = $payload['userId'] ?? null;

        if (! is_string($sessionId) || $sessionId === '') {
            $conn->close();

            return;
        }

        // Retrieve connection config from cache (one-time use). Stored
        // encrypted by the issuer; decrypt with the shared APP_KEY.
        $raw = Cache::pull("terminal-stream-pty:{$sessionId}");
        if ($raw === null) {
            $conn->close();

            return;
        }

        try {
            $configData = is_array($raw) ? $raw : $this->encrypter->decrypt($raw);
        } catch (\Throwable $e) {
            $conn->close();

            return;
        }

        if (! is_array($configData)) {
            $conn->close();

            return;
        }

        // Defense in depth: re-check the connection policy on the server, so a
        // token minted for a disallowed target (e.g. an off-allow-list SSH
        // host) is refused even if issuance was somehow bypassed.
        if (! (new ConnectionPolicy)->allows($configData)) {
            Log::warning("[web-terminal-stream] connection policy rejected session {$sessionId}");
            $conn->close();

            return;
        }

        // Enforce resource caps before committing a PTY to this connection.
        // The token is already consumed (Cache::pull); a rejected connection
        // just closes — the client can retry once capacity frees up.
        $userId = self::normalizeUserId($userId);
        $reason = $this->capacityReason($userId);
        if ($reason !== null) {
            // Capacity actually denied to a user — the number an operator has
            // to see before customers start reporting it.
            $this->metrics?->refusedConnection($reason);
            Log::warning("[web-terminal-stream] refused session {$sessionId}: {$reason}");
            $conn->write("HTTP/1.1 503 Service Unavailable\r\nConnection: close\r\nContent-Length: 0\r\n\r\n");
            $conn->close();

            return;
        }

        // Send the upgrade response
        $conn->write(Message::toString($response));

        // Create PTY bridge. A failed SSH login, a proc_open failure, or bad
        // config must NOT escape this callback — an uncaught throwable would
        // propagate out of the event loop and kill the whole server, dropping
        // every other live session.
        try {
            $connectionConfig = ConnectionConfig::fromArray($configData);
            $shell = $this->config['shell'] ?? '/bin/bash';

            $bridge = new TerminalPtyBridge($connectionConfig, $sessionId, $userId, $this->registry);

            // Timed because this is the blocking one: SSH connect + auth runs
            // synchronously on the loop, so every OTHER session on this worker
            // is frozen for exactly this long.
            $connectStartedAt = microtime(true);
            $bridge->start($shell);
            $connectMs = (microtime(true) - $connectStartedAt) * 1000;
        } catch (\Throwable $e) {
            Log::warning("[web-terminal-stream] failed to start terminal for session {$sessionId}: {$e->getMessage()}");
            $conn->close();

            return;
        }

        $this->bridges[$id] = $bridge;
        $this->connections[$id] = $conn;
        $this->userIds[$id] = $userId;
        $this->startedAt[$id] = time();
        $this->sessionIds[$id] = $sessionId;
        $this->connectionTypes[$id] = is_string($configData['type'] ?? null) ? $configData['type'] : 'local';

        // From here the loop owns the wakeups for this session.
        $this->watchBridge($id);

        $this->metrics?->sessionOpened($this->connectionTypes[$id], $connectMs);

        // Set up WebSocket message buffer for this connection.
        // expectMask = true because browser clients always mask frames.
        $this->buffers[$id] = new MessageBuffer(
            new CloseFrameChecker,
            function ($msg) use ($id) {
                $this->handleMessage($id, $msg->getPayload());
            },
            function ($frame) use ($conn, $id) {
                if ($frame->getOpcode() === Frame::OP_CLOSE) {
                    $conn->close();
                    $this->handleClose($id);
                }
            },
            true, // expectMask: browser clients always send masked frames
        );
    }

    private function handleMessage(int $id, string $payload): void
    {
        $bridge = $this->bridges[$id] ?? null;
        if ($bridge === null) {
            return;
        }

        try {
            $decoded = @json_decode($payload, true);
            if ($decoded !== null && ($decoded['type'] ?? null) === 'resize') {
                $bridge->resize((int) $decoded['cols'], (int) $decoded['rows']);

                return;
            }

            $bridge->write($payload);
        } catch (\Throwable) {
            $this->closeSession($id);
        }
    }

    private function handleClose(int $id): void
    {
        // Deregister BEFORE terminating: the loop must stop watching a stream
        // while that stream is still a live resource, or it keeps a handle to
        // a closed descriptor and wakes up forever on it.
        $this->unwatchBridge($id);

        if (isset($this->bridges[$id])) {
            $this->metrics?->sessionClosed($this->connectionTypes[$id] ?? 'local');
        }

        $bridge = $this->bridges[$id] ?? null;
        if ($bridge !== null) {
            try {
                $bridge->terminate();
            } catch (\Throwable) {
                // Best-effort close: the loop must stay healthy even if the
                // bridge's terminate path errors.
            }
        }

        // Record the end of the session. The server is the only place that
        // reliably observes every disconnect (a killed browser tab never runs
        // the client-side teardown). De-duplicated in the logger.
        $sessionId = $this->sessionIds[$id] ?? null;
        if ($this->logger !== null && $sessionId !== null) {
            try {
                $this->logger->logServerDisconnection(
                    $sessionId,
                    $this->userIds[$id] ?? null,
                    $this->connectionTypes[$id] ?? null,
                );
            } catch (\Throwable) {
                // Logging must never destabilise the event loop.
            }
        }

        unset(
            $this->bridges[$id],
            $this->connections[$id],
            $this->buffers[$id],
            $this->userIds[$id],
            $this->startedAt[$id],
            $this->sessionIds[$id],
            $this->connectionTypes[$id],
        );
    }

    /**
     * Called periodically to stream PTY output to WebSocket clients.
     */
    public function tick(): void
    {
        if ($this->bridges === []) {
            return;
        }

        // Timed because this is the loop's own heartbeat: a sweep that grows is
        // a worker running out of headroom, visible before users feel it.
        $startedAt = microtime(true);

        foreach (array_keys($this->bridges) as $id) {
            $this->pumpSession($id);
        }

        $this->metrics?->sweepTook((microtime(true) - $startedAt) * 1000);
    }

    /**
     * Terminate and unregister a single session from the event loop.
     *
     * Used both from explicit close events and from the loop's safety net
     * (tick / handleMessage) when a bridge throws unexpectedly.
     */
    /**
     * Drain one session's PTY and forward whatever it produced.
     *
     * Called by the loop the moment a transport becomes readable (event mode)
     * and by the periodic sweep (both modes). Draining in a loop matters: one
     * readable notification can cover many buffered packets, and phpseclib
     * hands them over a `read()` at a time, so returning after the first would
     * leave output stranded until the next wakeup.
     */
    private function pumpSession(int $id): void
    {
        $bridge = $this->bridges[$id] ?? null;

        if ($bridge === null) {
            return;
        }

        try {
            if (! $bridge->isRunning()) {
                // The shell exited (e.g. the user typed `exit`) or the SSH
                // transport dropped. Close the socket and evict the bridge —
                // otherwise a finished session leaks here forever and the
                // client is never told the terminal is gone.
                $this->closeSession($id);

                return;
            }

            $conn = $this->connections[$id] ?? null;

            // Bounded so a firehose (`yes`, a huge `cat`) cannot starve the
            // other sessions sharing this loop: we take a big bite, then yield
            // and let the loop come back to us.
            for ($chunk = 0; $chunk < 64; $chunk++) {
                $output = $bridge->read();

                if ($output === '') {
                    break;
                }

                if ($conn !== null) {
                    // Server-to-client frames are not masked per RFC6455.
                    $frame = new Frame($output, true, Frame::OP_TEXT);
                    $conn->write($frame->getContents());
                }
            }
        } catch (\Throwable) {
            // One bad session must not crash the shared event loop or poison
            // any of the other active sessions. Close it cleanly and move on.
            $this->closeSession($id);
        }
    }

    private function closeSession(int $id): void
    {
        $conn = $this->connections[$id] ?? null;
        if ($conn !== null) {
            $conn->close();
        }

        $this->handleClose($id);
    }

    /**
     * Why a new connection cannot be admitted, or null if it can.
     *
     * Enforces the total live-PTY cap and the per-user session cap. A limit of
     * 0 means unlimited. Kept as a small pure-ish method so it is unit-testable
     * without a real socket or token.
     */
    /**
     * The user id exactly as the application keys its users — int for
     * auto-increment tables, string for ULID/UUID keys, null when unknown.
     * Casting to int turned every ULID into 0, so the server-side disconnect
     * audit row was written with user_id = 0 and rejected by the foreign key
     * (seen on a ULID-keyed app 2026-09-07).
     */
    public static function normalizeUserId(mixed $userId): int|string|null
    {
        if (is_int($userId)) {
            return $userId > 0 ? $userId : null;
        }

        if (is_string($userId)) {
            $userId = trim($userId);

            if ($userId === '' || $userId === '0') {
                return null;
            }

            return ctype_digit($userId) ? (int) $userId : $userId;
        }

        return null;
    }

    public function capacityReason(int|string|null $userId): ?string
    {
        if ($this->maxConnections > 0 && count($this->bridges) >= $this->maxConnections) {
            return "server at capacity ({$this->maxConnections} connections)";
        }

        if ($this->maxSessionsPerUser > 0 && $userId !== null) {
            $forUser = 0;
            foreach ($this->userIds as $owner) {
                if ($owner === $userId) {
                    $forUser++;
                }
            }

            if ($forUser >= $this->maxSessionsPerUser) {
                return "user {$userId} at session limit ({$this->maxSessionsPerUser})";
            }
        }

        return null;
    }

    /**
     * Close any session whose PTY has outlived max_session_lifetime.
     *
     * The registry-level reap in ReactPhpProvider kills the orphaned OS
     * process; this closes the matching WebSocket so the browser is not left
     * staring at a dead terminal. Called from the provider's periodic timer.
     */
    public function reapExpired(int $maxLifetimeSeconds): void
    {
        if ($maxLifetimeSeconds <= 0) {
            return;
        }

        $cutoff = time() - $maxLifetimeSeconds;

        foreach ($this->startedAt as $id => $startedAt) {
            if ($startedAt < $cutoff) {
                Log::info("[web-terminal-stream] reaping session over max lifetime (conn {$id})");
                $this->closeSession($id);
            }
        }
    }

    /**
     * Terminate every live session and clear all state.
     *
     * Wired to SIGINT/SIGTERM by the provider so Ctrl-C on `terminal-stream:serve`
     * tears down PTYs and SSH channels instead of orphaning them.
     */
    public function shutdown(): void
    {
        foreach (array_keys($this->bridges) as $id) {
            $this->closeSession($id);
        }
    }

    /**
     * Number of live sessions — exposed for health checks and tests.
     */
    public function activeConnectionCount(): int
    {
        return count($this->bridges);
    }
}
