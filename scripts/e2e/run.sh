#!/usr/bin/env bash
#
# Full e2e run: scaffold the host app (if needed), start the docker sshd
# target, boot `php artisan serve` + the WebSocket server, then run
# Playwright. All extra args are forwarded to `npx playwright test`.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
APP_DIR="$ROOT/tests/e2e-app"

bash "$ROOT/scripts/e2e/setup.sh"

docker compose -f "$ROOT/tests/docker/compose.yaml" up -d --wait sshd

SERVE_PID=""
WS_PID=""

cleanup() {
    [[ -n "$SERVE_PID" ]] && kill "$SERVE_PID" 2>/dev/null || true
    [[ -n "$WS_PID" ]] && kill "$WS_PID" 2>/dev/null || true
    # artisan serve's inner `php -S` worker can outlive its parent.
    pkill -f "php -S 127.0.0.1:8000" 2>/dev/null || true
    pkill -f "terminal-stream:serve --host=127.0.0.1 --port=8091" 2>/dev/null || true
}
trap cleanup EXIT INT TERM

(cd "$APP_DIR" && exec php artisan serve --host=127.0.0.1 --port=8000) > /dev/null 2>&1 &
SERVE_PID=$!

(cd "$APP_DIR" && exec php artisan terminal-stream:serve --host=127.0.0.1 --port=8091) > /dev/null 2>&1 &
WS_PID=$!

# Port-poll wait loop (no external wait-on dependency).
wait_port() {
    local host="$1" port="$2" tries="${3:-120}"
    for ((i = 0; i < tries; i++)); do
        if (exec 3<>"/dev/tcp/${host}/${port}") 2>/dev/null; then
            exec 3>&- 3<&-
            return 0
        fi
        sleep 0.5
    done
    echo "Timed out waiting for ${host}:${port}" >&2
    return 1
}

wait_port 127.0.0.1 8000
wait_port 127.0.0.1 8091

cd "$ROOT"
npx playwright test "$@"
