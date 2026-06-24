<?php

namespace App\Models;

use App\Enums\ChangeClassification;
use App\Enums\ChangeStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectChange extends Model
{
    protected $fillable = [
        'project_id', 'message_id', 'quote_id', 'agent_run_id',
        'title', 'description', 'classification', 'status', 'applied_at',
    ];

    protected $casts = [
        'classification' => ChangeClassification::class,
        'status' => ChangeStatus::class,
        'applied_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function agentRun(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class);
    }
}
