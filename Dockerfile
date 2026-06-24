# Overcloud panel — single-container deploy (temp/demo, SQLite)
FROM php:8.4-cli AS app

RUN apt-get update && apt-get install -y --no-install-recommends \
        libzip-dev libsqlite3-dev libonig-dev unzip git \
    && docker-php-ext-install pdo pdo_mysql pdo_sqlite zip bcmath mbstring \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY . /app

# Build with a valid .env so artisan scripts can run; defer package discovery.
RUN cp .env.production .env \
    && composer install --no-dev --optimize-autoloader --no-interaction --no-progress --no-scripts \
    && php artisan package:discover --ansi \
    && chmod +x /app/docker/entrypoint.sh

EXPOSE 8080
CMD ["sh", "/app/docker/entrypoint.sh"]
