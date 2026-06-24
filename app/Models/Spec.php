<?php

namespace App\Models;

use App\Concerns\GeneratesUuid;
use App\Enums\SpecStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Spec extends Model
{
    use GeneratesUuid;

    protected $fillable = [
        'lead_id', 'uuid', 'version', 'title', 'summary', 'content',
        'status', 'pdf_path', 'client_feedback',
        'sent_at', 'agreed_at', 'changes_requested_at',
    ];

    protected $casts = [
        'status' => SpecStatus::class,
        'content' => 'array',
        'version' => 'integer',
        'sent_at' => 'datetime',
        'agreed_at' => 'datetime',
        'changes_requested_at' => 'datetime',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class);
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
