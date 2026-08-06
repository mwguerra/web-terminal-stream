<?php

declare(strict_types=1);

namespace MWGuerra\WebTerminalStream\Tests\Benchmarks\Support;

use Ratchet\Client\Connector as WsConnector;
use Ratchet\Client\WebSocket;
use Ratchet\RFC6455\Messaging\MessageInterface;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Socket\Connector as TcpConnector;

/**
 * Concurrent WebSocket stress fleet for `composer stress`.
 *
 * Opens every fleet connection on one ReactPHP event loop (Pawl client),
 * waits for the remote shell prompt, then runs an echo ping-pong per
 * connection — each ping a remotely-expanded arithmetic marker, so a
 * matched response proves a real PTY round-trip — until the connection's
 * assigned lifetime (3–10s spread) elapses. After the fleet is fully
 * established, the over-cap probe opens extra connections that the server
 * is expected to refuse at `stream.max_connections`.
 *
 * Framework-free: token URLs are minted by the caller (needs app context);
 * this class only drives sockets and aggregates timings.
 */
final class StressDriver
{
    /** Minimum quiet gap between a pong and the next ping, per connection. */
    private const PING_GAP_SECONDS = 0.25;

    /**
     * Grace period past the longest lifetime before the run is force-stopped.
     * Establishment is the slow phase: the server performs each session's SSH
     * connect + auth synchronously on its event loop, so a 100-connection
     * storm drains at roughly 0.3–0.5s per session before conversations start.
     */
    private const RUN_GRACE_SECONDS = 90.0;

    /**
     * Pawl fabricates a port-qualified Origin when none is given, which the
     * server's allow-list (APP_URL) rejects. Sending the allowed origin keeps
     * the Origin check exercised on every stressed handshake.
     */
    private const HEADERS = ['Origin' => 'http://localhost'];

    private int $established = 0;

    private int $connectFailures = 0;

    private int $unexpectedCloses = 0;

    private int $cleanCloses = 0;

    private int $overcapRejected = 0;

    private int $overcapAccepted = 0;

    /**
     * Set once every over-cap probe connection has an outcome. Fleet sessions
     * whose lifetime elapses earlier defer their close until then — otherwise
     * early closes free capacity and the server (correctly) accepts the probe,
     * turning the cap assertion into a race.
     */
    private bool $probeComplete = false;

    /**
     * The run is two-phase: every session connects and waits at its prompt
     * (quiet), and only when the whole fleet has settled do conversations —
     * and the over-cap probe — begin. Without the barrier, established
     * sessions' ping-pong load starves the remaining SSH key exchanges into
     * connect timeouts, and early closes race the probe.
     */
    private bool $conversing = false;

    /** @var array<int, callable> Sessions at prompt, waiting for the barrier. */
    private array $starters = [];

    /** @var array<int, float> */
    private array $connectMs = [];

    /** @var array<int, float> */
    private array $ttfbMs = [];

    /** @var array<int, float> */
    private array $rttMs = [];

    /** @var array<int, string> */
    private array $errors = [];

    /**
     * @param  array<int, string>  $fleetUrls  One token-authenticated ws:// URL per fleet connection.
     * @param  array<int, string>  $overcapUrls  Valid URLs expected to be refused at capacity.
     */
    public function __construct(
        private readonly array $fleetUrls,
        private readonly array $overcapUrls,
        private readonly int $maxLifetimeSeconds = 10,
        private readonly ?int $deadlineSeconds = null,
    ) {}

    /**
     * Run the whole scenario. Returns the summary array (also used by the
     * caller for assertions).
     *
     * @return array<string, mixed>
     */
    public function run(string $profile): array
    {
        $loop = Loop::get();
        $connector = new WsConnector($loop, new TcpConnector([
            'timeout' => 10,
        ]));

        $startedAt = hrtime(true);
        $pending = count($this->fleetUrls);
        $live = 0;
        $probeLaunched = false;
        $probePending = 0;

        // The run ends only when every fleet session has settled AND closed
        // AND the over-cap probe has all its outcomes — whichever finishes
        // last. Stopping on any single condition kills live sessions early.
        $maybeStop = function () use (&$pending, &$live, &$probeLaunched, &$probePending, $loop): void {
            if ($pending === 0 && $live === 0 && $probeLaunched && $probePending === 0) {
                $loop->addTimer(0.2, fn () => $loop->stop());
            }
        };

        $launchProbe = function () use (&$probeLaunched, &$probePending, $connector, $maybeStop): void {
            if ($probeLaunched) {
                return;
            }
            $probeLaunched = true;

            // Fleet settled → open the conversation barrier first, so the
            // probe arrives while every fleet PTY is live and counted.
            $this->conversing = true;
            foreach ($this->starters as $start) {
                $start();
            }
            $this->starters = [];

            $probePending = count($this->overcapUrls);

            foreach ($this->overcapUrls as $url) {
                $connector($url, [], self::HEADERS)->then(
                    function (WebSocket $ws) use (&$probePending, $maybeStop): void {
                        // The server accepted a connection beyond its cap.
                        $this->overcapAccepted++;
                        $probePending--;
                        $this->probeComplete = $this->probeComplete || $probePending === 0;
                        $ws->close();
                        $maybeStop();
                    },
                    function (\Throwable $e) use (&$probePending, $maybeStop): void {
                        $this->overcapRejected++;
                        $probePending--;
                        $this->probeComplete = $this->probeComplete || $probePending === 0;
                        $maybeStop();
                    },
                );
            }
        };

        foreach ($this->fleetUrls as $i => $url) {
            // Deterministic 3–10s lifetime spread across the fleet.
            $lifetime = 3 + ($i % ($this->maxLifetimeSeconds - 2));

            // 10ms launch ramp: still a connection storm (lifetimes ≥3s keep
            // the whole fleet concurrently live), but the SSH key exchanges
            // don't all serialize into the connect timeout.
            $loop->addTimer($i * 0.010, function () use ($connector, $url, $i, $lifetime, $loop, &$pending, &$live, $launchProbe, $maybeStop): void {
                $connectStart = hrtime(true);

                $connector($url, [], self::HEADERS)->then(
                    function (WebSocket $ws) use ($i, $lifetime, $connectStart, $loop, &$pending, &$live, $launchProbe, $maybeStop): void {
                        $this->established++;
                        $live++;
                        $pending--;
                        $this->connectMs[] = (hrtime(true) - $connectStart) / 1e6;

                        $this->attachSession($ws, $i, $lifetime, $loop, $live, $maybeStop);

                        // Fleet fully settled → fire the over-cap probe while
                        // every fleet PTY is still alive and counted.
                        if ($pending === 0) {
                            $launchProbe();
                        }
                    },
                    function (\Throwable $e) use ($i, &$pending, $launchProbe): void {
                        $this->connectFailures++;
                        $pending--;
                        $this->errors[] = "conn {$i}: ".$e->getMessage();
                        if ($pending === 0) {
                            $launchProbe();
                        }
                    },
                );
            });
        }

        // Safety net: stop the loop even if sessions wedge. High-latency SSH
        // legs need a wider window (serial setup ≈ RTT-bound per session).
        $deadline = $this->deadlineSeconds ?? (int) ($this->maxLifetimeSeconds + self::RUN_GRACE_SECONDS);
        $loop->addTimer($deadline, fn () => $loop->stop());

        $loop->run();

        return [
            'profile' => $profile,
            'fleet' => count($this->fleetUrls),
            'established' => $this->established,
            'connect_failures' => $this->connectFailures,
            'clean_closes' => $this->cleanCloses,
            'unexpected_closes' => $this->unexpectedCloses,
            'overcap_attempted' => count($this->overcapUrls),
            'overcap_rejected' => $this->overcapRejected,
            'overcap_accepted' => $this->overcapAccepted,
            'connect_ms' => self::percentiles($this->connectMs),
            'ttfb_ms' => self::percentiles($this->ttfbMs),
            'rtt_ms' => self::percentiles($this->rttMs),
            'rtt_samples' => count($this->rttMs),
            'duration_s' => round((hrtime(true) - $startedAt) / 1e9, 2),
            'errors' => array_slice($this->errors, 0, 10),
        ];
    }

    private function attachSession(WebSocket $ws, int $i, int $lifetime, LoopInterface $loop, int &$live, callable $maybeStop): void
    {
        $buffer = '';
        $sawFirstByte = false;
        $promptSeen = false;
        $pinging = false;
        $seq = 0;
        $marker = null;
        $pingSentAt = null;
        $closedByUs = false;
        $connectedAt = hrtime(true);
        $conversationStart = null;

        $sendPing = function () use ($ws, $i, &$seq, &$marker, &$pingSentAt, &$buffer): void {
            $seq++;
            // $((...)) expands remotely: the typed line never contains the
            // final marker, so a match proves executed shell output.
            $marker = "p{$i}x".(100000 + $seq);
            $pingSentAt = hrtime(true);
            $buffer = '';
            $ws->send("echo p{$i}x\$((100000+{$seq}))\n");
        };

        // A session past its lifetime must not close while the over-cap
        // probe is still pending — an early close frees capacity and turns
        // the cap assertion into a race. Poll until the probe settles.
        $closeWhenProbeDone = function () use (&$closeWhenProbeDone, $ws, &$closedByUs, $loop): void {
            if ($this->probeComplete || $this->overcapUrls === []) {
                $closedByUs = true;
                $ws->close(1000);

                return;
            }
            $loop->addTimer(0.25, $closeWhenProbeDone);
        };

        $beginConversation = function () use (&$pinging, &$conversationStart, $sendPing): void {
            $pinging = true;
            $conversationStart = hrtime(true);
            $sendPing();
        };

        $ws->on('message', function (MessageInterface $msg) use (
            &$buffer, &$sawFirstByte, &$promptSeen, &$pinging, &$marker, &$pingSentAt,
            $connectedAt, &$conversationStart, $beginConversation, $sendPing, $loop, $lifetime, &$closedByUs, $closeWhenProbeDone
        ): void {
            $buffer .= $msg->getPayload();

            if (! $sawFirstByte) {
                $sawFirstByte = true;
                $this->ttfbMs[] = (hrtime(true) - $connectedAt) / 1e6;
            }

            // Shell prompt reached → converse now if the fleet barrier is
            // open, otherwise wait quietly until it is.
            if (! $promptSeen && str_contains($buffer, '$')) {
                $promptSeen = true;
                if ($this->conversing) {
                    $beginConversation();
                } else {
                    $this->starters[] = $beginConversation;
                }

                return;
            }

            if ($pinging && $marker !== null && str_contains($buffer, $marker)) {
                $this->rttMs[] = (hrtime(true) - $pingSentAt) / 1e6;
                $marker = null;

                $elapsed = (hrtime(true) - $conversationStart) / 1e9;
                if ($elapsed >= $lifetime) {
                    $closeWhenProbeDone();

                    return;
                }

                $loop->addTimer(self::PING_GAP_SECONDS, function () use (&$closedByUs, $sendPing): void {
                    if (! $closedByUs) {
                        $sendPing();
                    }
                });
            }
        });

        $ws->on('close', function () use (&$closedByUs, &$live, $maybeStop, $i): void {
            if ($closedByUs) {
                $this->cleanCloses++;
            } else {
                $this->unexpectedCloses++;
                $this->errors[] = "conn {$i}: closed by peer before lifetime elapsed";
            }

            $live--;
            $maybeStop();
        });
    }

    /**
     * @param  array<int, float>  $samples
     * @return array<string, float>|null
     */
    public static function percentiles(array $samples): ?array
    {
        if ($samples === []) {
            return null;
        }

        sort($samples);
        $n = count($samples);
        $at = fn (float $q): float => $samples[min($n - 1, (int) floor($n * $q))];

        return [
            'p50' => round($at(0.50), 1),
            'p95' => round($at(0.95), 1),
            'p99' => round($at(0.99), 1),
            'min' => round($samples[0], 1),
            'max' => round($samples[$n - 1], 1),
            'mean' => round(array_sum($samples) / $n, 1),
        ];
    }
}
