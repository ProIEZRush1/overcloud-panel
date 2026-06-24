<?php

namespace App\Models;

use App\Concerns\GeneratesUuid;
use App\Enums\ProjectStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Project extends Model
{
    use GeneratesUuid;
    use SoftDeletes;

    protected $fillable = [
        'lead_id', 'quote_id', 'maintenance_plan_id', 'whatsapp_account_id', 'uuid',
        'name', 'slug', 'type', 'status', 'brief', 'repo_url', 'repo_branch',
        'coolify_app_uuid', 'prod_url', 'test_url', 'domain', 'whatsapp_group_jid',
        'maintenance_active', 'started_at', 'delivered_at',
    ];

    protected $casts = [
        'status' => ProjectStatus::class,
        'brief' => 'array',
        'maintenance_active' => 'boolean',
        'started_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function maintenancePlan(): BelongsTo
    {
        return $this->belongsTo(MaintenancePlan::class);
    }

    public function whatsappAccount(): BelongsTo
    {
        return $this->belongsTo(WhatsAppAccount::class);
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class);
    }

    public function changes(): HasMany
    {
        return $this->hasMany(ProjectChange::class);
    }

    public function credentials(): HasMany
    {
        return $this->hasMany(ProjectCredential::class);
    }

    public function agentRuns(): MorphMany
    {
        return $this->morphMany(AgentRun::class, 'subject');
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
