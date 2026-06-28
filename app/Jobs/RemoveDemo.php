<?php

namespace App\Jobs;

use App\Models\Lead;
use App\Services\DeployService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Frees a lost lead's demo deployment (Coolify app + DNS). The lead/conversation record is kept
 * in the panel — only the deployed resources are removed.
 */
class RemoveDemo implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 120;

    public function __construct(public int $leadId) {}

    public function handle(DeployService $deploy): void
    {
        $lead = Lead::find($this->leadId);
        if ($lead) {
            $deploy->removeDemo($lead);
        }
    }
}
