<?php

namespace App\Models;

use App\Enums\WhatsAppAccountStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WhatsAppAccount extends Model
{
    protected $table = 'whatsapp_accounts';

    protected $fillable = [
        'label', 'session_name', 'phone', 'jid', 'status',
        'is_default', 'auto_reply', 'last_connected_at', 'meta',
    ];

    protected $casts = [
        'status' => WhatsAppAccountStatus::class,
        'is_default' => 'boolean',
        'auto_reply' => 'boolean',
        'last_connected_at' => 'datetime',
        'meta' => 'array',
    ];

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function isConnected(): bool
    {
        return $this->status === WhatsAppAccountStatus::Connected;
    }
}
