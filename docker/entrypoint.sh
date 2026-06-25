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

# Non-root 'builder' user for headless Claude Code (it refuses --dangerously-skip-permissions as root).
if ! id builder >/dev/null 2>&1; then useradd -m builder 2>/dev/null || adduser -D -h /home/builder builder 2>/dev/null || true; fi
if [ -n "$CLAUDE_CREDS_JSON" ] && id builder >/dev/null 2>&1; then
  mkdir -p /home/builder/.claude
  printf '%s' "$CLAUDE_CREDS_JSON" > /home/builder/.claude/.credentials.json
  chmod 600 /home/builder/.claude/.credentials.json
  chown -R builder /home/builder 2>/dev/null || true
fi
mkdir -p storage/builds && chmod 777 storage/builds

# Workers: a fast lane for bot replies, a separate slow lane for site deploys.
php artisan queue:work --queue=default --tries=1 --sleep=2 --timeout=180 >> storage/logs/worker.log 2>&1 &
php artisan queue:work --queue=deploy --tries=1 --sleep=3 --timeout=1800 >> storage/logs/deploy.log 2>&1 &

exec php artisan serve --host 0.0.0.0 --port 8080
