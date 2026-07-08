<?php

declare(strict_types=1);

namespace MWGuerra\WebTerminalStream\WebSocket;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Message;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use MWGuerra\WebTerminalStream\Data\ConnectionConfig;
use MWGuerra\WebTerminalStream\Security\ConnectionPolicy;
use Ratchet\RFC6455\Handshake\RequestVerifier;
use Ratchet\RFC6455\Handshake\ServerNegotiator;
use Ratchet\RFC6455\Messaging\CloseFrameChecker;
use Ratchet\RFC6455\Messaging\Frame;
use Ratchet\RFC6455\Messaging\MessageBuffer;
use React\Socket\ConnectionInterface;

class ReactPhpWebSocketServer
{
    /** @var array<int, TerminalPtyBridge> Maps connection object ID to PTY bridge */
    private array $bridges = [];

    /** @var array<int, ConnectionInterface> Maps connection object ID to connection */
    private array $connections = [];

    /** @var array<int, MessageBuffer> Maps connection object ID to message buffer */
    private array $buffers = [];

    /** @var array<int, int> Maps connection object ID to the owning user id */
    private array $userIds = [];

    /** @var array<int, int> Maps connection object ID to its start timestamp */
    private array $startedAt = [];

    private int $maxConnections;

    private int $maxSessionsPerUser;

    private int $maxHandshakeBytes;

    private PtySessionRegistry $registry;

    private Encrypter $encrypter;

    private array $config;

    private ServerNegotiator $negotiator;

    private OriginValidator $originValidator;

    public function __construct(
        PtySessionRegistry $registry,
        Encrypter $encrypter,
        array $config,
    ) {
        $this->registry = $registry;
        $this->encrypter = $encrypter;
        $this->config = $config;
        $this->maxConnections = (int) ($config['max_connections'] ?? 100);
        $this->maxSessionsPerUser = (int) ($config['max_sessions_per_user'] ?? 10);
        $this->maxHandshakeBytes = (int) ($config['max_handshake_bytes'] ?? 16384);
        $this->negotiator = new ServerNegotiator(
            new RequestVerifier,
            new HttpFactory,
        );
        $this->originValidator = new OriginValidator($config['allowed_origins'] ?? []);
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

            // Feed data to WebSocket message buffer
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
        $reason = $this->capacityReason(is_int($userId) ? $userId : (is_numeric($userId) ? (int) $userId : null));
        if ($reason !== null) {
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

            $bridge = new TerminalPtyBridge($connectionConfig, $sessionId, (int) ($userId ?? 0), $this->registry);
            $bridge->start($shell);
        } catch (\Throwable $e) {
            Log::warning("[web-terminal-stream] failed to start terminal for session {$sessionId}: {$e->getMessage()}");
            $conn->close();

            return;
        }

        $this->bridges[$id] = $bridge;
        $this->connections[$id] = $conn;
        $this->userIds[$id] = is_numeric($userId) ? (int) $userId : 0;
        $this->startedAt[$id] = time();

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
        $bridge = $this->bridges[$id] ?? null;
        if ($bridge !== null) {
            try {
                $bridge->terminate();
            } catch (\Throwable) {
                // Best-effort close: the loop must stay healthy even if the
                // bridge's terminate path errors.
            }
        }

        unset(
            $this->bridges[$id],
            $this->connections[$id],
            $this->buffers[$id],
            $this->userIds[$id],
            $this->startedAt[$id],
        );
    }

    /**
     * Called periodically to stream PTY output to WebSocket clients.
     */
    public function tick(): void
    {
        foreach ($this->bridges as $id => $bridge) {
            try {
                if (! $bridge->isRunning()) {
                    // The shell exited (e.g. the user typed `exit`) or the SSH
                    // transport dropped. Close the socket and evict the bridge —
                    // otherwise a finished session leaks here forever and the
                    // client is never told the terminal is gone.
                    $this->closeSession($id);

                    continue;
                }

                $output = $bridge->read();
                if ($output === '') {
                    continue;
                }

                $conn = $this->connections[$id] ?? null;
                if ($conn !== null) {
                    // Server-to-client frames are not masked per RFC6455.
                    $frame = new Frame($output, true, Frame::OP_TEXT);
                    $conn->write($frame->getContents());
                }
            } catch (\Throwable) {
                // One bad session must not crash the shared event loop or
                // poison any of the other active sessions. Close it cleanly
                // and move on.
                $this->closeSession($id);
            }
        }
    }

    /**
     * Terminate and unregister a single session from the event loop.
     *
     * Used both from explicit close events and from the loop's safety net
     * (tick / handleMessage) when a bridge throws unexpectedly.
     */
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
    public function capacityReason(?int $userId): ?string
    {
        if ($this->maxConnections > 0 && count($this->bridges) >= $this->maxConnections) {
            return "server at capacity ({$this->maxConnections} connections)";
        }

        if ($this->maxSessionsPerUser > 0 && $userId !== null && $userId > 0) {
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
