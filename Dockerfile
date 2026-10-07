# ============================================================
# Product Service - Laravel 13
# Multi-stage production Docker image
# ============================================================

# ------------------------------------------------------------
# Stage 1: PHP dependencies
# ------------------------------------------------------------
FROM composer:2 AS composer

WORKDIR /app

COPY composer.json composer.lock ./

RUN composer install \
    --no-dev \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader \
    --no-scripts


# ------------------------------------------------------------
# Stage 2: Frontend assets
# ------------------------------------------------------------
FROM node:22-alpine AS frontend

WORKDIR /app

COPY package.json package-lock.json* ./

RUN npm install

COPY . .

RUN npm run build


# ------------------------------------------------------------
# Stage 3: Production PHP-FPM
# ------------------------------------------------------------
FROM php:8.4-fpm-alpine AS production

WORKDIR /var/www/html

# Required PHP extensions
RUN apk add --no-cache \
        icu-dev \
        libzip-dev \
        oniguruma-dev \
        postgresql-dev \
        sqlite-dev \
    && docker-php-ext-install \
        bcmath \
        intl \
        mbstring \
        opcache \
        pdo \
        pdo_pgsql \
        pdo_sqlite \
        zip

# Copy Composer dependencies
COPY --from=composer /app/vendor ./vendor

# Copy application source
COPY . .

# Copy built frontend assets
COPY --from=frontend /app/public/build ./public/build

# Laravel writable directories
RUN mkdir -p \
        storage/framework/cache \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chown -R www-data:www-data \
        storage \
        bootstrap/cache \
    && chmod -R 775 \
        storage \
        bootstrap/cache

# PHP production configuration
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

EXPOSE 9000

CMD ["php-fpm", "-F"]
