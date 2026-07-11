#!/usr/bin/env bash
set -euo pipefail

APP_HOME=/var/www/html
cd "$APP_HOME"

echo "[entrypoint] Preparing storage directories..."
mkdir -p \
  storage/framework/cache \
  storage/framework/sessions \
  storage/framework/views \
  storage/logs \
  storage/app/private \
  bootstrap/cache

# storage/app may be a mounted (persistent) volume — make sure it's writable.
chown -R www-data:www-data storage bootstrap/cache || true

# Public symlink for the 'public' disk (harmless if it already exists).
php artisan storage:link --quiet 2>/dev/null || true

echo "[entrypoint] Discovering packages..."
php artisan package:discover --ansi || true

# Optional schema migrations (shared DB — off by default; never migrate:fresh).
if [ "${RUN_MIGRATIONS_ON_STARTUP:-false}" = "true" ]; then
  echo "[entrypoint] Running migrations..."
  php artisan migrate --force
else
  echo "[entrypoint] Skipping migrations (set RUN_MIGRATIONS_ON_STARTUP=true to enable)."
fi

# Build caches with the runtime environment now available.
echo "[entrypoint] Caching config / routes / views..."
php artisan config:clear --quiet || true
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Re-assert ownership after caches were written (as root above).
chown -R www-data:www-data storage bootstrap/cache || true

echo "[entrypoint] Starting supervisord..."
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
