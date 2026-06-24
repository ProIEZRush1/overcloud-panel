# Overcloud panel — simple single-container deploy (temp/demo)
FROM php:8.4-cli AS app

RUN apt-get update && apt-get install -y --no-install-recommends \
        libzip-dev libpng-dev libonig-dev unzip git \
    && docker-php-ext-install pdo pdo_mysql pdo_sqlite zip bcmath \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY . /app

RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress \
    && chmod +x /app/docker/entrypoint.sh

EXPOSE 8080
CMD ["/app/docker/entrypoint.sh"]
