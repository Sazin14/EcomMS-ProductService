# syntax=docker/dockerfile:1

# ============================================================
# Product Service - Laravel 13 (PHP 8.4, PHP-FPM on :9000)
# ============================================================

# ------------------------------------------------------------
# Stage 1: PHP dependencies (no dev, no scripts)
# ------------------------------------------------------------
FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./

RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --prefer-dist \
    --optimize-autoloader \
    --no-scripts

# ------------------------------------------------------------
# Stage 2: Frontend assets (Vite + Tailwind)
# ------------------------------------------------------------
FROM node:22-alpine AS frontend

WORKDIR /app

COPY package.json package-lock.json* ./
RUN if [ -f package-lock.json ]; then npm ci; else npm install; fi

COPY vite.config.js ./
COPY resources ./resources
COPY public ./public

RUN npm run build

# ------------------------------------------------------------
# Stage 3: Production PHP-FPM
# ------------------------------------------------------------
FROM php:8.4-fpm-alpine AS production

WORKDIR /var/www/html

# Extensions: PostgreSQL + SQLite drivers, intl, zip, bcmath, opcache
# (mbstring, tokenizer, xml, ctype, fileinfo, pdo are already in the base image)
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions bcmath intl opcache pdo_pgsql pdo_sqlite zip \
    && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY --from=vendor /app/vendor ./vendor
COPY . .
COPY --from=frontend /app/public/build ./public/build

# Run the skipped composer scripts (package discovery), then prepare writable dirs.
# No .env is baked in: configuration must come from the runtime environment.
RUN php artisan package:discover --ansi \
    && mkdir -p storage/framework/cache/data storage/framework/sessions \
        storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    PHP_OPCACHE_VALIDATE_TIMESTAMPS=0

RUN { \
        echo "opcache.enable=1"; \
        echo "opcache.validate_timestamps=0"; \
        echo "opcache.memory_consumption=128"; \
        echo "opcache.max_accelerated_files=20000"; \
        echo "expose_php=0"; \
    } > "$PHP_INI_DIR/conf.d/99-production.ini"

EXPOSE 9000

CMD ["php-fpm", "-F"]
