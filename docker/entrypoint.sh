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

# Non-root 'builder' user for headless Claude Code (it refuses --dangerously-skip-permissions as root).
if ! id builder >/dev/null 2>&1; then useradd -m builder 2>/dev/null || adduser -D -h /home/builder builder 2>/dev/null || true; fi

# Claude Code subscription credentials. The CLI auto-refreshes the OAuth token in this file,
# so /home/builder/.claude is a PERSISTENT volume — only (re)seed from env when the stored
# token is missing or already expired, otherwise we'd clobber the live refreshed token on
# every restart and the bot would log out every few hours.
seed_creds() {
  dir="$1"; owner="$2"
  mkdir -p "$dir"
  file="$dir/.credentials.json"
  need_seed=1
  if [ -s "$file" ]; then
    # Keep the existing (live, CLI-refreshed) file unless its token already expired.
    exp=$(php -r '$j=json_decode(@file_get_contents($argv[1]),true); echo (int)(($j["claudeAiOauth"]["expiresAt"]??0)/1000);' "$file" 2>/dev/null || echo 0)
    now=$(date +%s)
    if [ "${exp:-0}" -gt "$now" ]; then need_seed=0; fi
  fi
  if [ "$need_seed" = "1" ] && [ -n "$CLAUDE_CREDS_JSON" ]; then
    printf '%s' "$CLAUDE_CREDS_JSON" > "$file"
    chmod 600 "$file"
    echo "claude credentials seeded into $dir"
  else
    echo "claude credentials kept (live token) in $dir"
  fi
  [ -n "$owner" ] && chown -R "$owner" "$dir" 2>/dev/null || true
}
seed_creds /root/.claude ""
seed_creds /home/builder/.claude builder
mkdir -p storage/builds && chmod 777 storage/builds

# Workers: a fast lane for bot replies, a separate slow lane for site deploys.
php artisan queue:work --queue=default --tries=1 --sleep=2 --timeout=180 >> storage/logs/worker.log 2>&1 &
php artisan queue:work --queue=deploy --tries=1 --sleep=3 --timeout=1800 >> storage/logs/deploy.log 2>&1 &

# Scheduler (daily billing/dunning run).
php artisan schedule:work >> storage/logs/schedule.log 2>&1 &

exec php artisan serve --host 0.0.0.0 --port 8080
