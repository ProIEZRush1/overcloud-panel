<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Daily billing run: reminders, pause overdue projects, monthly maintenance.
Schedule::command('payments:dunning')->dailyAt('09:00')->timezone('America/Mexico_City')->withoutOverlapping();

// Continuously watch every bot conversation for spam loops / stuck clients.
Schedule::command('bot:monitor')->everyMinute()->withoutOverlapping();

// Once a day, send ONE reminder to leads who went silent mid-funnel (re-engage warm prospects).
Schedule::command('bot:followups')->dailyAt('11:00')->timezone('America/Mexico_City')->withoutOverlapping();
