<?php

namespace App\Console\Commands;

use App\Services\BillingService;
use Illuminate\Console\Command;

class RunDunning extends Command
{
    protected $signature = 'payments:dunning';

    protected $description = 'Send payment reminders, pause overdue projects, and raise monthly maintenance charges';

    public function handle(BillingService $billing): int
    {
        $billing->runDunning();
        $this->info('Dunning run complete.');

        return self::SUCCESS;
    }
}
