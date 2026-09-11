#!/bin/sh
set -e

APP_KEY_FILE=/var/www/html/storage/app/app_key

# The image ships no .env — configuration arrives as environment variables —
# so `key:generate` has no file to write to. Instead the key is generated once
# and kept in the storage volume, which every container shares and which
# survives restarts: a key that changed on each boot would invalidate every
# session and everything encrypted with it.
if [ -z "$APP_KEY" ]; then
    if [ "$APP_ROLE" = "web" ]; then
        if [ ! -s "$APP_KEY_FILE" ]; then
            php artisan key:generate --show > "$APP_KEY_FILE"
            echo "Сгенерирован APP_KEY (сохранён в storage/app/app_key)."
            echo "Для продакшена задайте APP_KEY в .env явно."
        fi
    else
        # Workers wait for the web container to produce it.
        printf 'Waiting for the application key'
        attempts=0
        while [ ! -s "$APP_KEY_FILE" ] && [ "$attempts" -lt 60 ]; do
            printf '.'
            sleep 1
            attempts=$((attempts + 1))
        done
        echo ' ready'
    fi

    if [ -s "$APP_KEY_FILE" ]; then
        APP_KEY=$(cat "$APP_KEY_FILE")
        export APP_KEY
    fi
fi

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
    php artisan migrate --force
    php artisan db:seed --force
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
fi

exec "$@"
