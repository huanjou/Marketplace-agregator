#!/bin/sh
set -e

# The project root is bind-mounted from the host, so files created by root
# (e.g. `docker compose exec` without -u) stay root-owned and become
# unwritable for the php-fpm workers running as www-data. Normalise the
# runtime-writable trees on every container start.
for dir in storage bootstrap/cache; do
    [ -d "$dir" ] || continue
    chown -R www-data:www-data "$dir" 2>/dev/null || true
    chmod -R ug+rwX "$dir" 2>/dev/null || true
done

exec docker-php-entrypoint "$@"
