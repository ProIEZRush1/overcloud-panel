<?php

namespace App\Models;

use App\Enums\ConversationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

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

    /** A live-site change captured from a voice note / ambiguous message, awaiting the
     *  client's explicit "sí" before we touch their site. Expires after 30 minutes so a
     *  stale, forgotten confirmation never fires later. Returns the instruction or null. */
    public function pendingChange(): ?string
    {
        $pc = $this->meta['pending_change'] ?? null;
        if (! is_array($pc) || empty($pc['instruction']) || empty($pc['at'])) {
            return null;
        }
        if (Carbon::parse($pc['at'])->lt(now()->subMinutes(30))) {
            return null;
        }

        return (string) $pc['instruction'];
    }

    public function setPendingChange(string $instruction): void
    {
        $this->meta = array_merge($this->meta ?? [], [
            'pending_change' => ['instruction' => $instruction, 'at' => now()->toIso8601String()],
        ]);
        $this->save();
    }

    public function clearPendingChange(): void
    {
        $meta = $this->meta ?? [];
        unset($meta['pending_change']);
        $this->meta = $meta;
        $this->save();
    }
}
