#!/bin/sh
set -eu

if [ ! -f .env ]; then
    cp .env.example .env
fi

if ! grep -q '^APP_KEY=base64:' .env; then
    php artisan key:generate --force
fi

if [ ! -f vendor/autoload.php ]; then
    composer install --no-interaction --prefer-dist --no-progress
fi

# APP_CONFIG_CACHE points to container-local /tmp, never the bind-mounted tree.
# Rebuild on every start so restarts and new deployments use current runtime env.
php artisan config:cache

exec "$@"
