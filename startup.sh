#!/bin/bash

# 1. Map the Nginx root to /public
cp /home/site/wwwroot/default.conf /etc/nginx/sites-available/default
service nginx reload

# 2. Fix permissions
mkdir -p /home/site/wwwroot/storage/framework/cache
mkdir -p /home/site/wwwroot/storage/framework/sessions
mkdir -p /home/site/wwwroot/storage/framework/views
mkdir -p /home/site/wwwroot/storage/logs
chmod -R 775 /home/site/wwwroot/storage /home/site/wwwroot/bootstrap/cache

# 3. Increase PHP-FPM worker limits
sed -i 's/^pm.max_children = .*/pm.max_children = 50/g' /usr/local/etc/php-fpm.d/www.conf
sed -i 's/^pm.start_servers = .*/pm.start_servers = 10/g' /usr/local/etc/php-fpm.d/www.conf
sed -i 's/^pm.min_spare_servers = .*/pm.min_spare_servers = 5/g' /usr/local/etc/php-fpm.d/www.conf
sed -i 's/^pm.max_spare_servers = .*/pm.max_spare_servers = 15/g' /usr/local/etc/php-fpm.d/www.conf
sed -i 's/^;pm.max_requests = .*/pm.max_requests = 500/g' /usr/local/etc/php-fpm.d/www.conf

# 4. Clear the cache immediately so the first request uses current settings
php /home/site/wwwroot/artisan config:clear
php /home/site/wwwroot/artisan cache:clear file || true

# 5. Run remaining Laravel tasks in the background
(
  if [ "${RUN_MIGRATIONS_ON_STARTUP:-false}" = "true" ]; then
    echo "Running migrations..."
    php /home/site/wwwroot/artisan migrate --force
  else
    echo "Skipping startup migrations. Set RUN_MIGRATIONS_ON_STARTUP=true to enable them."
  fi

  echo "Rebuilding optimization cache..."
  php /home/site/wwwroot/artisan config:cache
  php /home/site/wwwroot/artisan route:cache
  php /home/site/wwwroot/artisan view:cache
  echo "Background tasks finished."
) &

# 6. Self-hosted OCR worker (ocr-worker/) — the document intake pipeline calls it
#    at OCR_SERVICE_URL (default http://127.0.0.1:8077). Toggle with ENABLE_OCR_WORKER.
#    NOTE: /usr is NOT persisted across App Service cold starts, so Tesseract +
#    LibreOffice are (re)installed on each fresh container; the Python venv lives
#    under /home (persisted) and is built only once. If cold-start time becomes a
#    problem, bake these into a custom Docker image instead — same design, faster boot.
if [ "${ENABLE_OCR_WORKER:-true}" = "true" ]; then
(
  OCR_DIR=/home/site/wwwroot/ocr-worker

  # Point the sidecar at the Linux binaries (its defaults are Windows paths).
  export TESSERACT_CMD="${TESSERACT_CMD:-/usr/bin/tesseract}"
  export SOFFICE_PATH="${SOFFICE_PATH:-/usr/bin/soffice}"

  # Install system binaries when missing (fresh container after a cold start).
  if ! command -v tesseract >/dev/null 2>&1 || ! command -v soffice >/dev/null 2>&1; then
    echo "[$(date)] Installing OCR system dependencies (tesseract, libreoffice)..."
    apt-get update -y \
      && apt-get install -y --no-install-recommends \
           python3 python3-venv python3-pip \
           tesseract-ocr libreoffice-core libreoffice-writer \
      || echo "[$(date)] WARNING: OCR dependency install failed; extraction will be unavailable."
  fi

  # Build the Python venv once (persists under /home).
  if [ ! -x "$OCR_DIR/.venv/bin/uvicorn" ]; then
    echo "[$(date)] Creating OCR worker virtualenv..."
    python3 -m venv "$OCR_DIR/.venv" \
      && "$OCR_DIR/.venv/bin/pip" install --no-cache-dir -r "$OCR_DIR/requirements.txt" \
      || echo "[$(date)] WARNING: OCR venv setup failed."
  fi

  # Resilient run loop, bound to localhost (the worker has no auth).
  while true; do
    echo "[$(date)] Starting OCR worker on 127.0.0.1:8077..."
    ( cd "$OCR_DIR" && "$OCR_DIR/.venv/bin/uvicorn" app.main:app --host 127.0.0.1 --port 8077 )
    echo "[$(date)] OCR worker exited, restarting in 10 seconds..."
    sleep 10
  done
) >> /home/site/wwwroot/storage/logs/ocr-worker.log 2>&1 &
fi

# 7. Queue worker — OCR pipeline jobs (Convert/Extract/Submit) run on the DEFAULT
#    queue; 'imports' is kept for backward compatibility. Per-job $tries (OCR = 3)
#    override the CLI --tries.
(
  while true; do
    echo "[$(date)] Starting queue worker..."
    php /home/site/wwwroot/artisan queue:work \
      --queue=default,imports \
      --sleep=5 \
      --tries=1 \
      --timeout=3600 \
      --max-time=3500
    echo "[$(date)] Queue worker exited, restarting in 10 seconds..."
    sleep 10
  done
) >> /home/site/wwwroot/storage/logs/queue-worker.log 2>&1 &

# 8. Scheduler — drives portal:fetch-intake-emails (every 2 min) and
#    portal:flag-overdue-reviews (daily). App Service has no cron, so loop it.
(
  while true; do
    php /home/site/wwwroot/artisan schedule:run --no-interaction
    sleep 60
  done
) >> /home/site/wwwroot/storage/logs/scheduler.log 2>&1 &

echo "Startup script finished. Starting php-fpm."
exec php-fpm
