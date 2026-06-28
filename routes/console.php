<?php

use App\Contracts\Assistant;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Keep the Claude CLI token warm: a small ping every 3h forces the CLI to refresh its OAuth token
// (the refresh is written back to the persistent creds volume) so it doesn't lapse during quiet
// periods and the bot silently fall back to deterministic replies.
Artisan::command('ai:keepalive', function (Assistant $assistant) {
    if (! $assistant->isEnabled()) {
        return;
    }
    $ok = $assistant->complete('Responde solo: ok');
    Log::info('ai:keepalive', ['ok' => filled($ok)]);
})->purpose('Refresh the Claude CLI token so it never lapses');

Schedule::command('ai:keepalive')->everyThreeHours()->withoutOverlapping();

// Daily billing run: reminders, pause overdue projects, monthly maintenance.
Schedule::command('payments:dunning')->dailyAt('09:00')->timezone('America/Mexico_City')->withoutOverlapping();

// Continuously watch every bot conversation for spam loops / stuck clients.
Schedule::command('bot:monitor')->everyMinute()->withoutOverlapping();

// Once a day, send ONE reminder to leads who went silent mid-funnel (re-engage warm prospects).
Schedule::command('bot:followups')->dailyAt('11:00')->timezone('America/Mexico_City')->withoutOverlapping();
