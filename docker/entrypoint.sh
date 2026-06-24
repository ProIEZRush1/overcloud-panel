#!/usr/bin/env sh
set -e
cd /app

# Always use the baked production env (stable APP_KEY, SQLite, real APP_URL).
cp .env.production .env

mkdir -p database storage/framework/cache storage/framework/sessions storage/framework/views
[ -f database/database.sqlite ] || touch database/database.sqlite

php artisan migrate --seed --force
php artisan storage:link || true
php artisan config:cache

exec php artisan serve --host 0.0.0.0 --port 8080
