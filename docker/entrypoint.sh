#!/usr/bin/env sh
set -e
cd /app

# First boot: seed .env from the production template.
[ -f .env ] || cp .env.production .env

# SQLite store for the demo instance.
mkdir -p database storage/framework/cache storage/framework/sessions storage/framework/views
[ -f database/database.sqlite ] || touch database/database.sqlite

php artisan key:generate --force
php artisan config:clear
php artisan migrate --seed --force
php artisan storage:link || true

exec php artisan serve --host 0.0.0.0 --port 8080
