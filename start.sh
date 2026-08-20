#!/usr/bin/env bash
# Idempotent bootstrap for the marketplace aggregator stack.
# Safe to re-run: existing .env, data and seeded rows are left untouched.
#
# Usage:
#   ./start.sh           # up + key + migrate + seed
#   ./start.sh --build   # same, but force-rebuild container images first
set -euo pipefail
cd "$(dirname "$0")"

if ! command -v docker >/dev/null 2>&1; then
    echo "ERROR: docker is not installed." >&2
    exit 1
fi
if docker compose version >/dev/null 2>&1; then
    COMPOSE=(docker compose)
elif command -v docker-compose >/dev/null 2>&1; then
    COMPOSE=(docker-compose)
else
    echo "ERROR: docker compose plugin (or docker-compose) is required." >&2
    exit 1
fi

echo "==> .env"
if [ ! -f .env ]; then
    cp .env.example .env
    echo "    created .env from .env.example"
else
    echo "    .env already present"
fi

echo "==> containers"
if [ "${1:-}" = "--build" ]; then
    "${COMPOSE[@]}" up -d --build
else
    "${COMPOSE[@]}" up -d
fi

echo "==> waiting for the app container"
for i in $(seq 1 40); do
    if "${COMPOSE[@]}" exec -T app php artisan --version >/dev/null 2>&1; then
        break
    fi
    sleep 3
done
"${COMPOSE[@]}" exec -T app php artisan --version >/dev/null

echo "==> APP_KEY"
if grep -qE '^APP_KEY=.+' .env; then
    echo "    already set"
else
    "${COMPOSE[@]}" exec -T app php artisan key:generate
    echo "    generated"
fi

echo "==> migrations (postgres may still be warming up, retrying)"
for i in $(seq 1 20); do
    if "${COMPOSE[@]}" exec -T app php artisan migrate --force; then
        break
    fi
    sleep 3
done

echo "==> seeders"
"${COMPOSE[@]}" exec -T app php artisan db:seed --force

echo
echo "Done. Stack is up:"
echo "  public search : http://localhost:8080/"
echo "  admin panel   : http://localhost:8080/admin  (admin@example.com / password)"
echo
echo "Note: Ozon scraping needs a sticky RU residential proxy in OZON_PROXY_URL (.env)."
