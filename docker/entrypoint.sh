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
# Release any stale atomic locks (cache:clear does NOT touch the cache_locks table). A worker killed
# mid-deploy can leave a ShouldBeUnique/deploy lock held for up to an hour, which silently turns every
# re-dispatch into a no-op (the build looks "stuck queued"). A fresh container holds none legitimately.
php artisan tinker --execute="try { \Illuminate\Support\Facades\DB::table('cache_locks')->delete(); } catch (\Throwable \$e) {}" 2>/dev/null || true
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
  # Seed ONLY when the creds file is missing. If it already exists on the persistent volume we keep
  # it untouched — the CLI auto-refreshes the OAuth token in place (the access token "looking
  # expired" is normal; the refresh token renews it). Re-seeding from the static CLAUDE_CREDS_JSON
  # snapshot would clobber the live, refreshed token and log the bot out every few hours.
  need_seed=1
  [ -s "$file" ] && need_seed=0
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

# Workers: a fast lane for bot replies, plus TWO parallel slow lanes for site deploys so a long
# build (a full store can take an hour) never starves another lead's demo behind it. SUPERVISED —
# each runs in an auto-respawn loop, so if a worker dies (OOM, a hung build, an uncaught error) it
# comes back instead of silently halting the whole queue. Per-project Cache::lock still guarantees
# no two builds touch the SAME project at once; the two lanes only ever build DIFFERENT projects.
# --timeout raised to 5400s (matches the jobs' own timeout) so a big first build is never SIGKILLed.
( while true; do php artisan queue:work --queue=default --tries=1 --sleep=2 --timeout=180 --max-time=3600 >> storage/logs/worker.log 2>&1; echo "[entrypoint] default worker exited; restarting in 2s" >> storage/logs/worker.log; sleep 2; done ) &
for lane in 1 2; do
  ( while true; do php artisan queue:work --queue=deploy --tries=1 --sleep=3 --timeout=5400 --max-time=10800 >> storage/logs/deploy.log 2>&1; echo "[entrypoint] deploy worker (lane $lane) exited; restarting in 2s" >> storage/logs/deploy.log; sleep 2; done ) &
done

# Scheduler (daily billing/dunning run), also supervised.
( while true; do php artisan schedule:work >> storage/logs/schedule.log 2>&1; sleep 2; done ) &

exec php artisan serve --host 0.0.0.0 --port 8080
