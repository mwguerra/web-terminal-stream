#!/usr/bin/env bash
#
# Connection stress harness: N concurrent WebSocket→PTY sessions against the
# real terminal-stream:serve, swept across latency profiles.
#
#   composer stress                            # full matrix, 100 conns + over-cap probe
#   WTS_STRESS_CONNECTIONS=10 composer stress  # lighter fleet
#   WTS_STRESS_PROFILES="baseline client-500ms" composer stress
#
# Latency legs:
#   client-<N>ms — toxiproxy between the WS client and the server (browser-side lag)
#   ssh-<N>ms    — tc netem on the sshd container egress (distant-SSH-host lag)
# The value is one-way delay per direction (netem semantics), so a 500ms
# profile adds ~1s to every round-trip.
#
# Advisory only — never CI-gated. Results: docs/benchmarks/stress-latest.json
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

# Fleet + server + SSH legs ≈ 3 fds per connection; default shell limits
# (256 on macOS) starve a 100-connection run.
ulimit -n 4096 2>/dev/null || true
COMPOSE=(docker compose -f "$ROOT/tests/docker/compose.yaml")
TOXI_API="http://127.0.0.1:8474"
OUT_DIR="$(mktemp -d)"

CONNECTIONS="${WTS_STRESS_CONNECTIONS:-100}"
OVERCAP="${WTS_STRESS_OVERCAP:-10}"
PROFILES="${WTS_STRESS_PROFILES:-baseline client-10ms client-500ms ssh-10ms ssh-500ms}"

echo "→ Starting sshd + toxiproxy containers"
"${COMPOSE[@]}" --profile stress up -d --build --wait sshd toxiproxy

# One proxy, reused across profiles; toxins are added/removed per profile.
curl -sf -X DELETE "$TOXI_API/proxies/wts" >/dev/null 2>&1 || true
curl -sf -X POST "$TOXI_API/proxies" \
    -d '{"name":"wts","listen":"0.0.0.0:8092","upstream":"host.docker.internal:8098"}' >/dev/null

netem_clear() { "${COMPOSE[@]}" exec -T sshd tc qdisc del dev eth0 root 2>/dev/null || true; }
toxics_clear() {
    curl -sf -X DELETE "$TOXI_API/proxies/wts/toxics/lat_down" >/dev/null 2>&1 || true
    curl -sf -X DELETE "$TOXI_API/proxies/wts/toxics/lat_up" >/dev/null 2>&1 || true
}
cleanup() {
    netem_clear
    toxics_clear
}
trap cleanup EXIT INT TERM

for profile in $PROFILES; do
    netem_clear
    toxics_clear
    port=8098
    fleet="$CONNECTIONS"
    deadline=""

    case "$profile" in
        client-*ms)
            ms="${profile#client-}"; ms="${ms%ms}"
            curl -sf -X POST "$TOXI_API/proxies/wts/toxics" \
                -d "{\"name\":\"lat_down\",\"type\":\"latency\",\"stream\":\"downstream\",\"attributes\":{\"latency\":${ms},\"jitter\":0}}" >/dev/null
            curl -sf -X POST "$TOXI_API/proxies/wts/toxics" \
                -d "{\"name\":\"lat_up\",\"type\":\"latency\",\"stream\":\"upstream\",\"attributes\":{\"latency\":${ms},\"jitter\":0}}" >/dev/null
            port=8092
            ;;
        ssh-*ms)
            ms="${profile#ssh-}"; ms="${ms%ms}"
            "${COMPOSE[@]}" exec -T sshd tc qdisc replace dev eth0 root netem delay "${ms}ms"
            if [ "$ms" -ge 100 ]; then
                # The server performs SSH setup synchronously on its event
                # loop, so per-session establishment is RTT-bound (~6.5s at
                # 500ms one-way). A full 100-connection fleet would take ~11
                # minutes to establish — bound this profile's fleet instead
                # (override via WTS_STRESS_SLOW_SSH_CONNECTIONS).
                fleet="${WTS_STRESS_SLOW_SSH_CONNECTIONS:-25}"
                deadline=420
                echo "NOTE: $profile fleet bounded to $fleet (serial SSH setup is RTT-bound; see docs/benchmarks/README.md)"
            fi
            ;;
        baseline) ;;
        *)
            echo "Unknown profile: $profile" >&2
            exit 1
            ;;
    esac

    echo ""
    echo "═══ profile: $profile (fleet=$fleet overcap=$OVERCAP, ws port=$port) ═══"

    WTS_STRESS=1 \
    WTS_STRESS_PROFILE="$profile" \
    WTS_STRESS_WS_PORT="$port" \
    WTS_STRESS_BIND=0.0.0.0 \
    WTS_STRESS_CONNECTIONS="$fleet" \
    WTS_STRESS_OVERCAP="$OVERCAP" \
    WTS_STRESS_DEADLINE="$deadline" \
    WTS_STRESS_OUT="$OUT_DIR/$profile.json" \
    php -d memory_limit=2G "$ROOT/vendor/bin/pest" --testsuite=Benchmarks --group=stress --colors=never
done

php "$ROOT/scripts/stress/report.php" "$OUT_DIR" "$ROOT/docs/benchmarks/stress-latest.json"
