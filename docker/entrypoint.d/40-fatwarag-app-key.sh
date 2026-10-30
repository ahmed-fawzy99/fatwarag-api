#!/bin/sh
# Fails the container at start when APP_KEY or BFF_KEY is missing, before serversideup's Laravel automations
# (50-laravel-automations.sh) build the caches from the runtime env.
if [ -z "${APP_KEY:-}" ]; then
    echo "fatwarag: APP_KEY is empty. Provide it in .env.production (php artisan key:generate --show)." >&2
    exit 1
fi
# Without BFF_KEY the API can't trust the visitor IP the web forwards, so every visitor would share the web server's limits.
if [ -z "${BFF_KEY:-}" ]; then
    echo "fatwarag: BFF_KEY is empty. Provide it in .env.production (the same value as the web's BFF_KEY)." >&2
    exit 1
fi
