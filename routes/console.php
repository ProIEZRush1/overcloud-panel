<?php

use App\Contracts\Assistant;
use App\Services\DeployService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Keep the Claude CLI token warm AND keep the env snapshot current. A ping forces the CLI to refresh
// its OAuth token (Claude ROTATES the refresh token on every refresh, invalidating the old one). We
// then copy the freshly-rotated creds back into this app's CLAUDE_CREDS_JSON env so that a future
// redeploy re-seeds the build agent from a VALID token — a stale snapshot is exactly what was logging
// the agent out after every deploy and silently breaking builds/changes.
Artisan::command('ai:keepalive', function (Assistant $assistant) {
    if (! $assistant->isEnabled()) {
        return;
    }
    $ok = $assistant->complete('Responde solo: ok');
    Log::info('ai:keepalive', ['ok' => filled($ok)]);
    try {
        $credsFile = rtrim((string) config('overcloud.ai.home', '/home/builder'), '/').'/.claude/.credentials.json';
        if ($ok !== null && is_readable($credsFile)) {
            $fresh = trim((string) file_get_contents($credsFile));
            if ($fresh !== '' && app(DeployService::class)->updatePanelEnv('CLAUDE_CREDS_JSON', $fresh)) {
                Log::info('ai:keepalive snapshotted fresh creds to env');
            }
        }
    } catch (Throwable $e) {
        Log::warning('ai:keepalive creds snapshot failed', ['e' => $e->getMessage()]);
    }
})->purpose('Refresh the Claude CLI token + keep the env snapshot current so the build agent never lapses');

// Every 30 min: the build agent shares one Claude account with the operator's laptop, which rotates the
// refresh token; refreshing + re-snapshotting often keeps the env credential current so a redeploy never
// re-seeds a stale (logged-out) token. (A dedicated API key would remove the sharing entirely.)
Schedule::command('ai:keepalive')->everyThirtyMinutes()->withoutOverlapping();

// Daily billing run: reminders, pause overdue projects, monthly maintenance.
Schedule::command('payments:dunning')->dailyAt('09:00')->timezone('America/Mexico_City')->withoutOverlapping();

// Continuously watch every bot conversation for spam loops / stuck clients.
Schedule::command('bot:monitor')->everyMinute()->withoutOverlapping();

// Once a day, send ONE reminder to leads who went silent mid-funnel (re-engage warm prospects).
Schedule::command('bot:followups')->dailyAt('11:00')->timezone('America/Mexico_City')->withoutOverlapping();

// Enforce the 5-day demo policy: remind ~24h before, then tear down the Coolify service if still unpaid.
Schedule::command('trials:expire')->dailyAt('10:00')->timezone('America/Mexico_City')->withoutOverlapping();
