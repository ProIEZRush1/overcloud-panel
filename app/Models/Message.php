<?php

namespace App\Models;

use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Message extends Model
{
    protected $fillable = [
        'conversation_id', 'sent_by_user_id', 'wa_message_id', 'direction', 'type',
        'sender_jid', 'body', 'media_path', 'media_mime', 'media_filename', 'caption',
        'quoted_wa_message_id', 'status', 'is_from_me', 'ai_generated', 'payload', 'wa_timestamp',
    ];

    protected $casts = [
        'direction' => MessageDirection::class,
        'type' => MessageType::class,
        'status' => MessageStatus::class,
        'is_from_me' => 'boolean',
        'ai_generated' => 'boolean',
        'payload' => 'array',
        'wa_timestamp' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by_user_id');
    }

    public function paymentProofs(): HasMany
    {
        return $this->hasMany(PaymentProof::class);
    }

    public function isInbound(): bool
    {
        return $this->direction === MessageDirection::In;
    }
}
