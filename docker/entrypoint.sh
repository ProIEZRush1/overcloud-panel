#!/usr/bin/env sh
set -e
cd /app

# Coolify writes /app/.env with the production env vars — keep it. Only fall
# back to the bundled template if (somehow) no env file was provided.
[ -f .env ] || cp .env.production .env

mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache

# Wait for the database, then migrate + seed (seeders are idempotent).
php artisan migrate --force --seed
php artisan storage:link || true
php artisan cache:clear || true
php artisan config:cache

# Background worker for WhatsApp bot replies + async jobs.
php artisan queue:work --tries=1 --sleep=2 --timeout=120 >> storage/logs/worker.log 2>&1 &

exec php artisan serve --host 0.0.0.0 --port 8080
