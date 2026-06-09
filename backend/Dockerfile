# Debe coincidir con composer.json ("php": "^8.5") y docs (PHP 8.5+).
FROM php:8.5.4-cli

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip zip libpq-dev libzip-dev libonig-dev postgresql-client dcmtk \
    && docker-php-ext-install pdo_pgsql bcmath zip mbstring \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY . .
RUN composer dump-autoload --optimize --no-dev \
    && mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 8000

ENTRYPOINT ["entrypoint.sh"]
CMD ["serve"]
