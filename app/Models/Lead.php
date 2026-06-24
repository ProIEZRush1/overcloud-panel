<?php

namespace App\Models;

use App\Concerns\GeneratesUuid;
use App\Enums\LeadStage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Lead extends Model
{
    use GeneratesUuid;
    use SoftDeletes;

    protected $fillable = [
        'uuid', 'whatsapp_account_id', 'service_id', 'maintenance_plan_id', 'assigned_to_user_id',
        'name', 'phone', 'email', 'company', 'source',
        'stage', 'service_type', 'summary', 'requirements', 'pages', 'languages',
        'budget_hint', 'deposit_percent', 'score', 'locale', 'notes', 'last_contact_at',
    ];

    protected $casts = [
        'stage' => LeadStage::class,
        'requirements' => 'array',
        'languages' => 'array',
        'pages' => 'integer',
        'deposit_percent' => 'integer',
        'score' => 'integer',
        'last_contact_at' => 'datetime',
    ];

    public function whatsappAccount(): BelongsTo
    {
        return $this->belongsTo(WhatsAppAccount::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function maintenancePlan(): BelongsTo
    {
        return $this->belongsTo(MaintenancePlan::class);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function specs(): HasMany
    {
        return $this->hasMany(Spec::class);
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class);
    }

    public function paymentRequests(): HasMany
    {
        return $this->hasMany(PaymentRequest::class);
    }

    public function project(): HasOne
    {
        return $this->hasOne(Project::class);
    }

    public function agentRuns(): MorphMany
    {
        return $this->morphMany(AgentRun::class, 'subject');
    }

    public function latestQuote(): HasOne
    {
        return $this->hasOne(Quote::class)->latestOfMany();
    }

    public function latestSpec(): HasOne
    {
        return $this->hasOne(Spec::class)->latestOfMany();
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
