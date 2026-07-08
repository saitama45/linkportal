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

# 6. Start queue worker for the 'imports' queue
(
  while true; do
    echo "[$(date)] Starting queue worker..."
    php /home/site/wwwroot/artisan queue:work \
      --queue=imports \
      --sleep=5 \
      --tries=1 \
      --timeout=3600 \
      --max-time=3500
    echo "[$(date)] Queue worker exited, restarting in 10 seconds..."
    sleep 10
  done
) >> /home/site/wwwroot/storage/logs/queue-worker.log 2>&1 &

echo "Startup script finished. Starting php-fpm."
exec php-fpm
