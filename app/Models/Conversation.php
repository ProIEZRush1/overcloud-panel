<?php

namespace App\Models;

use App\Enums\ConversationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Conversation extends Model
{
    protected $fillable = [
        'whatsapp_account_id', 'lead_id', 'contact_jid', 'contact_phone', 'contact_name',
        'is_group', 'status', 'ai_enabled', 'unread_count',
        'last_message_preview', 'last_message_at', 'snoozed_until', 'meta',
    ];

    protected $casts = [
        'status' => ConversationStatus::class,
        'is_group' => 'boolean',
        'ai_enabled' => 'boolean',
        'unread_count' => 'integer',
        'last_message_at' => 'datetime',
        'snoozed_until' => 'datetime',
        'meta' => 'array',
    ];

    public function whatsappAccount(): BelongsTo
    {
        return $this->belongsTo(WhatsAppAccount::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(Message::class)->latestOfMany();
    }

    /** Whether the AI agent is allowed to auto-reply right now. Groups are gated by
     *  ai_enabled too, so only explicitly-enabled project groups get the bot. */
    public function botMayReply(): bool
    {
        return $this->ai_enabled
            && $this->status === ConversationStatus::Bot;
    }
}
