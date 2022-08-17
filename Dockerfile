# syntax=docker/dockerfile:1

# === Install dependencies === #
FROM composer:latest as composer-build
WORKDIR /build

COPY /composer.json ./
RUN composer install --ignore-platform-reqs --prefer-dist --no-scripts --no-progress --no-interaction --no-dev --no-autoloader
RUN composer dump-autoload --optimize --apcu --no-dev

# === Copy app === #
FROM php:8.1-rc-cli-alpine
WORKDIR /app

COPY --from=composer-build /build/vendor /app/vendor
COPY /src /app/src

CMD ["php", "src/app.php"]