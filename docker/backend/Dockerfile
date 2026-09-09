FROM php:8.3-cli-alpine

RUN apk add --no-cache git icu-dev libzip-dev oniguruma-dev unzip \
    && docker-php-ext-install bcmath intl mbstring pdo_mysql zip

COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-scripts --no-progress

COPY backend ./
RUN composer dump-autoload --optimize \
    && chmod -R ug+rw storage bootstrap/cache

COPY docker/backend/entrypoint.sh /usr/local/bin/dejavoo-backend-entrypoint
RUN chmod +x /usr/local/bin/dejavoo-backend-entrypoint

EXPOSE 8000

ENTRYPOINT ["dejavoo-backend-entrypoint"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
