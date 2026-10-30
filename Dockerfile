# syntax=docker/dockerfile:1
# Production image for the FatwaRAG API: serversideup/php with FrankenPHP, running the app through Laravel Octane.
# One image, three roles (compose.prod.yaml picks the command): Octane web server, queue worker, scheduler.

# ---- Front-end assets (Vite) for the one Blade page the API serves at "/" ----
FROM node:22-bookworm-slim AS assets
WORKDIR /build
COPY package.json .npmrc ./
RUN npm install --no-audit --no-fund
COPY vite.config.js ./
COPY resources ./resources
COPY public ./public
RUN npm run build

# ---- PHP base: FrankenPHP and the extensions the app needs (the image already has pdo_pgsql, redis, pcntl,
# zip and opcache). gd is not installed: Filament 5 does not need it (no image processing here).
FROM serversideup/php:8.5-frankenphp AS base
USER root
RUN install-php-extensions intl bcmath pdo_pgsql redis pcntl
COPY --chmod=755 docker/entrypoint.d/ /etc/entrypoint.d/
USER www-data
WORKDIR /var/www/html

# ---- Composer dependencies (production only) ----
FROM base AS vendor
COPY --chown=www-data:www-data composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --no-autoloader

# ---- Final image ----
# PHP settings come from serversideup's PHP_* variables. opcache never revalidates: code never changes inside a
# running container. The image's HEALTHCHECK requests HEALTHCHECK_PATH on :8080 (Laravel's /up).
FROM base AS production
ENV PHP_OPCACHE_ENABLE=1 \
    PHP_OPCACHE_VALIDATE_TIMESTAMPS=0 \
    PHP_MEMORY_LIMIT=512M \
    PHP_UPLOAD_MAX_FILE_SIZE=8M \
    PHP_POST_MAX_SIZE=8M \
    HEALTHCHECK_PATH=/up \
    SHOW_WELCOME_MESSAGE=false
COPY --chown=www-data:www-data --from=vendor /var/www/html/vendor ./vendor
COPY --chown=www-data:www-data . .
COPY --chown=www-data:www-data --from=assets /build/public/build ./public/build

# Autoloader, package discovery and Filament's assets (composer's post-autoload-dump runs package:discover and
# filament:upgrade, which publishes Filament's CSS/JS/fonts into public/).
RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && composer dump-autoload --no-dev --optimize --classmap-authoritative --no-interaction

EXPOSE 8080
CMD ["php", "artisan", "octane:start", "--server=frankenphp", "--host=0.0.0.0", "--port=8080", "--caddyfile=docker/Caddyfile"]
