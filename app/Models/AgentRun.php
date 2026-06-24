<?php

namespace App\Models;

use App\Enums\AgentRunKind;
use App\Enums\AgentRunStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AgentRun extends Model
{
    protected $fillable = [
        'subject_type', 'subject_id', 'kind', 'status', 'model',
        'input', 'output', 'input_tokens', 'output_tokens', 'cost_cents',
        'error', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'kind' => AgentRunKind::class,
        'status' => AgentRunStatus::class,
        'input' => 'array',
        'output' => 'array',
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
        'cost_cents' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
