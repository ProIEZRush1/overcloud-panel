<?php

namespace App\Models;

use App\Enums\CredentialKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectCredential extends Model
{
    protected $fillable = ['project_id', 'kind', 'label', 'data', 'notes'];

    protected $casts = [
        'kind' => CredentialKind::class,
        'data' => 'encrypted:array',   // stored encrypted at rest
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
