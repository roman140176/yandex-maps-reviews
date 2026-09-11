# syntax=docker/dockerfile:1

# ---- Stage 1: build the SPA -------------------------------------------------
FROM node:22-alpine AS frontend

WORKDIR /build

COPY package*.json ./
RUN npm ci

COPY vite.config.js tsconfig.json ./
COPY resources ./resources
RUN npm run build

# ---- Stage 2: PHP dependencies ---------------------------------------------
FROM composer:2 AS vendor

WORKDIR /build

COPY composer.json composer.lock ./
# Scripts need the application files, which are not here yet.
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY . .
RUN composer dump-autoload --optimize --no-dev

# ---- Stage 3: runtime -------------------------------------------------------
FROM php:8.3-fpm-alpine AS runtime

# Runtime libraries stay; the -dev headers are installed as a virtual package
# and dropped afterwards, so the image keeps libicu/libzip that intl and zip
# link against at runtime.
RUN apk add --no-cache icu-libs libzip \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS icu-dev libzip-dev linux-headers \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql bcmath intl zip opcache pcntl \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps

COPY docker/php/php.ini /usr/local/etc/php/conf.d/app.ini

WORKDIR /var/www/html

COPY --from=vendor /build /var/www/html
COPY --from=frontend /build/public/build /var/www/html/public/build

# php-fpm runs as www-data; the framework needs to write to these.
RUN chown -R www-data:www-data storage bootstrap/cache

# nginx serves the document root from a shared volume, and a named volume keeps
# whatever was written into it first. Keeping a pristine copy in the image lets
# the entrypoint refresh that volume on every start, so a rebuilt image always
# ships its own assets instead of yesterday's.
RUN cp -a public /opt/public-dist

COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

EXPOSE 9000

ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]
