#!/bin/sh
set -e

# Wait for the database before doing anything that talks to it.
if [ -n "$DB_HOST" ]; then
    printf 'Waiting for %s:%s' "$DB_HOST" "${DB_PORT:-3306}"
    until php -r "exit(@fsockopen(getenv('DB_HOST'), (int) (getenv('DB_PORT') ?: 3306)) ? 0 : 1);" 2>/dev/null; do
        printf '.'
        sleep 1
    done
    echo ' ready'
fi

# Refresh the document root shared with nginx (see the Dockerfile note).
if [ -d /opt/public-dist ] && [ "$APP_ROLE" = "web" ]; then
    cp -a /opt/public-dist/. /var/www/html/public/
    chown -R www-data:www-data /var/www/html/public
fi

# Only the main web container prepares state; workers just start.
if [ "$APP_ROLE" = "web" ]; then
    [ -n "$APP_KEY" ] || php artisan key:generate --force
    php artisan migrate --force
    php artisan db:seed --force
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
fi

exec "$@"
