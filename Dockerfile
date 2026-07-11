# syntax=docker/dockerfile:1

# =============================================================================
# linkportal — single self-contained image for Azure App Service (Web App for
# Containers). Bundles the Laravel app (PHP-FPM + nginx), the queue worker, the
# scheduler, and the self-hosted OCR worker (Python FastAPI + Tesseract +
# LibreOffice), all supervised by supervisord. No extra Azure resource required.
# =============================================================================

# ---- Stage 1: install PHP dependencies (Composer) --------------------------
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
# Deps first (cached), without running artisan scripts (no runtime env yet).
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative


# ---- Stage 2: build front-end assets (Vite) --------------------------------
# resources/js/app.js imports ZiggyVue from vendor/tightenco/ziggy, so the
# Composer vendor dir MUST be present before `npm run build` — copy it from the
# vendor stage.
FROM node:20-bookworm-slim AS assets
WORKDIR /app
COPY package.json package-lock.json* ./
RUN npm ci
COPY . .
COPY --from=vendor /app/vendor ./vendor
RUN npm run build


# ---- Stage 3: runtime image ------------------------------------------------
FROM php:8.3-fpm-bookworm AS runtime

ENV DEBIAN_FRONTEND=noninteractive \
    APP_HOME=/var/www/html \
    # Point the OCR sidecar (pydantic) at the Linux binaries (defaults are Windows).
    TESSERACT_CMD=/usr/bin/tesseract \
    SOFFICE_PATH=/usr/bin/soffice

# --- System packages: web server, process manager, OCR stack, build libs ---
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        nginx supervisor gnupg2 ca-certificates curl unzip \
        # Compiler toolchain required by pecl / docker-php-ext-install
        $PHPIZE_DEPS \
        # PHP extension build deps
        libpng-dev libjpeg62-turbo-dev libfreetype6-dev libzip-dev libicu-dev libonig-dev \
        # OCR runtime: Tesseract + LibreOffice (headless) + fonts
        tesseract-ocr tesseract-ocr-eng \
        libreoffice-core libreoffice-writer libreoffice-calc \
        fonts-dejavu fonts-liberation \
        # Python for the ocr-worker sidecar
        python3 python3-venv python3-pip; \
    rm -rf /var/lib/apt/lists/*

# --- Microsoft ODBC Driver 18 (each step isolated so failures are obvious) --
RUN set -eux; \
    curl -fsSL https://packages.microsoft.com/keys/microsoft.asc | gpg --dearmor -o /usr/share/keyrings/microsoft-prod.gpg

RUN set -eux; \
    echo "deb [arch=amd64,armhf,arm64 signed-by=/usr/share/keyrings/microsoft-prod.gpg] https://packages.microsoft.com/debian/12/prod bookworm main" \
        > /etc/apt/sources.list.d/mssql-release.list

RUN set -eux; \
    apt-get update; \
    ACCEPT_EULA=Y apt-get install -y msodbcsql18; \
    apt-get install -y unixodbc-dev; \
    rm -rf /var/lib/apt/lists/*

# --- sqlsrv / pdo_sqlsrv PHP extensions -------------------------------------
RUN set -eux; \
    pecl channel-update pecl.php.net; \
    pecl install sqlsrv pdo_sqlsrv; \
    docker-php-ext-enable sqlsrv pdo_sqlsrv

# --- Core PHP extensions ----------------------------------------------------
RUN set -eux; \
    docker-php-ext-configure gd --with-freetype --with-jpeg; \
    docker-php-ext-install -j"$(nproc)" \
        bcmath pcntl gd zip intl exif mbstring opcache

WORKDIR ${APP_HOME}

# --- Application code + built assets + vendor -------------------------------
# Bring the composer-resolved app (includes vendor/) then overlay Vite assets.
COPY --from=vendor /app ${APP_HOME}
COPY --from=assets /app/public/build ${APP_HOME}/public/build

# --- OCR worker virtualenv (baked so cold starts are fast) ------------------
RUN set -eux; \
    python3 -m venv ${APP_HOME}/ocr-worker/.venv; \
    ${APP_HOME}/ocr-worker/.venv/bin/pip install --no-cache-dir --upgrade pip; \
    ${APP_HOME}/ocr-worker/.venv/bin/pip install --no-cache-dir -r ${APP_HOME}/ocr-worker/requirements.txt

# --- Config files -----------------------------------------------------------
COPY docker/nginx.conf /etc/nginx/sites-available/default
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-linkportal.ini
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# --- Writable dirs + ownership ---------------------------------------------
RUN set -eux; \
    mkdir -p ${APP_HOME}/storage/framework/cache \
             ${APP_HOME}/storage/framework/sessions \
             ${APP_HOME}/storage/framework/views \
             ${APP_HOME}/storage/logs \
             ${APP_HOME}/bootstrap/cache; \
    chown -R www-data:www-data ${APP_HOME}/storage ${APP_HOME}/bootstrap/cache

EXPOSE 8080

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
