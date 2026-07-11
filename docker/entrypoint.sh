#!/usr/bin/env bash
set -euo pipefail

APP_HOME=/var/www/html
cd "$APP_HOME"

# Persist uploaded files (template samples, intake documents, converted PDFs) on
# the App Service managed /home share so they survive container restarts — the
# container's own filesystem is ephemeral. Uses the already-mounted /home
# (WEBSITES_ENABLE_APP_SERVICE_STORAGE=true), so NO extra storage resource is
# needed. Only storage/app is redirected; framework caches stay local (fast).
PERSIST_DIR="/home/site/linkportal/storage-app"
if mkdir -p "$PERSIST_DIR/private" 2>/dev/null; then
  [ -L "$APP_HOME/storage/app" ] || rm -rf "$APP_HOME/storage/app"
  ln -sfn "$PERSIST_DIR" "$APP_HOME/storage/app"
  echo "[entrypoint] storage/app -> $PERSIST_DIR (persistent /home share)"
else
  echo "[entrypoint] WARNING: /home not writable; uploaded files will NOT persist across restarts."
fi

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

# Keep RBAC permissions in sync with the code (idempotent, additive). This is
# what makes new modules' menu items appear after a deploy. Never touches schema.
echo "[entrypoint] Syncing permissions..."
php artisan db:seed --class=PermissionSyncSeeder --force || echo "[entrypoint] WARNING: permission sync failed; continuing."

# Ensure the document-processing tables exist. Scoped to the two migrations that
# create the NEW portal_document_* / portal_intake_* tables (never the existing
# vendor/transaction ones). Idempotent: `migrate` records applied migrations, so
# this is a no-op once done; the exception-rule seeder uses firstOrCreate. Runs
# every boot (non-fatal) so it self-heals without env-var / image-timing fiddling.
echo "[entrypoint] Ensuring document-processing tables..."
php artisan migrate --path=database/migrations/portal/2026_07_10_000001_create_portal_document_processing_tables.php --force \
  || echo "[entrypoint] WARNING: doc-processing tables migration failed."
php artisan migrate --path=database/migrations/portal/2026_07_10_000003_create_portal_personal_access_tokens_table.php --force \
  || echo "[entrypoint] WARNING: personal-access-tokens migration failed."
php artisan db:seed --class=DocumentExceptionRuleSeeder --force \
  || echo "[entrypoint] WARNING: exception-rule seed failed."

# Optional schema migrations. The shared DB (tashelpdeskdb) already contains the
# full schema, so this stays OFF in production — a blanket `migrate` would try to
# recreate existing tables. Kept non-fatal so a migration error never crash-loops
# the container; apply new migrations manually via SSH with a scoped --path.
if [ "${RUN_MIGRATIONS_ON_STARTUP:-false}" = "true" ]; then
  echo "[entrypoint] Running migrations..."
  php artisan migrate --force || echo "[entrypoint] WARNING: migrations failed; continuing startup."
else
  echo "[entrypoint] Skipping migrations (RUN_MIGRATIONS_ON_STARTUP is not 'true')."
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
