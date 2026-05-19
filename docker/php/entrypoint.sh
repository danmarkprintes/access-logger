#!/bin/sh
set -e
cd /var/www/html

if [ ! -f vendor/autoload.php ]; then
    echo "Running composer install..."
    composer install --no-interaction --prefer-dist
fi

exec "$@"
