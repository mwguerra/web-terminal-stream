#!/usr/bin/env bash
#
# Scaffold the gitignored e2e host app (tests/e2e-app): Laravel 13 + Filament 5
# with this package symlinked in, fixture pages, seeded admin user, and the
# E2E backdoor login route. Idempotent — exits early if the app already
# exists; pass --force to rebuild from scratch.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
APP_DIR="$ROOT/tests/e2e-app"
FIXTURES="$ROOT/scripts/e2e/fixtures"

FORCE=0
if [[ "${1:-}" == "--force" ]]; then
    FORCE=1
fi

if [[ -d "$APP_DIR" && $FORCE -eq 0 ]]; then
    echo "e2e app already exists at $APP_DIR (use --force to rebuild)"
    exit 0
fi

rm -rf "$APP_DIR"

composer create-project laravel/laravel:^13.0 "$APP_DIR" --no-interaction --prefer-dist

cd "$APP_DIR"

# This package, symlinked from the repo root.
composer config repositories.wts '{"type": "path", "url": "../../", "options": {"symlink": true}}'
composer require "mwguerra/web-terminal-stream:@dev" "filament/filament:^5.0" -W --no-interaction

php artisan filament:install --panels --no-interaction

# Publish the package config + terminal_stream_logs migration.
php artisan terminal-stream:install --config --migration --no-tenant --force --no-interaction

# .env: override existing keys in place (dotenv is first-wins, appending
# duplicates does nothing), append new ones.
set_env() {
    local key="$1" value="$2"
    if grep -q "^${key}=" .env; then
        sed -i.bak "s|^${key}=.*|${key}=${value}|" .env && rm -f .env.bak
    else
        printf '%s=%s\n' "$key" "$value" >> .env
    fi
}

while IFS='=' read -r key value; do
    [[ -z "$key" || "$key" == \#* ]] && continue
    set_env "$key" "$value"
done < "$FIXTURES/env.e2e"

set_env DB_CONNECTION sqlite
set_env DB_DATABASE "$APP_DIR/database/database.sqlite"

# Fixtures: Filament pages (auto-discovered from app/Filament/Pages), seeder,
# backdoor login route.
mkdir -p app/Filament/Pages
cp "$FIXTURES/pages/"*.php app/Filament/Pages/
cp "$FIXTURES/DatabaseSeeder.php" database/seeders/DatabaseSeeder.php
cp "$FIXTURES/routes-e2e.php" routes/e2e.php
if ! grep -q "routes/e2e" routes/web.php; then
    printf "\nrequire __DIR__.'/e2e.php';\n" >> routes/web.php
fi

touch database/database.sqlite
php artisan migrate --seed --force

# Copy registered Filament package assets (package CSS/JS) into public/.
php artisan filament:assets

echo "e2e app ready at $APP_DIR"
