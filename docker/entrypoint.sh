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

# Claude Code subscription credentials for the AI worker (written from env, never baked).
if [ -n "$CLAUDE_CREDS_JSON" ]; then
  mkdir -p /root/.claude
  printf '%s' "$CLAUDE_CREDS_JSON" > /root/.claude/.credentials.json
  chmod 600 /root/.claude/.credentials.json
  echo "claude credentials written"
fi

# Workers: a fast lane for bot replies, a separate slow lane for site deploys.
php artisan queue:work --queue=default --tries=1 --sleep=2 --timeout=180 >> storage/logs/worker.log 2>&1 &
php artisan queue:work --queue=deploy --tries=1 --sleep=3 --timeout=600 >> storage/logs/deploy.log 2>&1 &

exec php artisan serve --host 0.0.0.0 --port 8080
