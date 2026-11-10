FROM composer:2 AS dependencies
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist

FROM php:8.3-cli-alpine
WORKDIR /app
COPY --from=dependencies /app/vendor ./vendor
COPY . .
CMD ["php", "bin/seed.php"]
