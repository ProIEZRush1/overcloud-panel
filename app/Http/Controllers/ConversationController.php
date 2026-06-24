<?php

namespace App\Http\Controllers;

use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\WhatsAppGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class ConversationController extends Controller
{
    public function __construct(private WhatsAppGateway $gateway) {}

    public function index()
    {
        return Inertia::render('inbox/Index', [
            'conversations' => $this->list(),
        ]);
    }

    public function show(Conversation $conversation)
    {
        $conversation->update(['unread_count' => 0]);
        $conversation->load('lead:id,uuid,name,stage', 'whatsappAccount:id,label');

        return Inertia::render('inbox/Show', [
            'conversations' => $this->list(),
            'conversation' => [
                'id' => $conversation->id,
                'name' => $conversation->contact_name ?? $conversation->contact_phone,
                'phone' => $conversation->contact_phone,
                'is_group' => $conversation->is_group,
                'status' => $conversation->status->value,
                'ai_enabled' => $conversation->ai_enabled,
                'account' => $conversation->whatsappAccount?->label,
                'lead_uuid' => $conversation->lead?->uuid,
                'lead_name' => $conversation->lead?->name,
            ],
            'messages' => $conversation->messages()->orderBy('id')->limit(200)->get()
                ->map(fn (Message $m) => [
                    'id' => $m->id,
                    'direction' => $m->direction->value,
                    'type' => $m->type->value,
                    'body' => $m->body,
                    'caption' => $m->caption,
                    'media_url' => $m->media_path ? Storage::url($m->media_path) : null,
                    'media_mime' => $m->media_mime,
                    'is_from_me' => $m->is_from_me,
                    'ai_generated' => $m->ai_generated,
                    'status' => $m->status->value,
                    'at' => ($m->wa_timestamp ?? $m->created_at)->format('d/m H:i'),
                ]),
        ]);
    }

    public function reply(Request $request, Conversation $conversation)
    {
        $data = $request->validate(['body' => 'required|string|max:4000']);
        $conversation->load('whatsappAccount');

        $message = $conversation->messages()->create([
            'direction' => MessageDirection::Out,
            'type' => MessageType::Text,
            'body' => $data['body'],
            'status' => MessageStatus::Pending,
            'is_from_me' => true,
            'sent_by_user_id' => $request->user()->id,
            'wa_timestamp' => now(),
        ]);
        $conversation->update([
            'last_message_at' => now(),
            'last_message_preview' => \Illuminate\Support\Str::limit($data['body'], 120),
            'status' => ConversationStatus::Human,
        ]);

        try {
            $res = $this->gateway->sendText($conversation->whatsappAccount->session_name, $conversation->contact_jid, $data['body']);
            $message->update(['status' => MessageStatus::Sent, 'wa_message_id' => $res['wa_message_id'] ?? null]);
        } catch (\Throwable $e) {
            $message->update(['status' => MessageStatus::Failed]);
        }

        return back();
    }

    public function toggleBot(Conversation $conversation)
    {
        $enabled = ! $conversation->ai_enabled;
        $conversation->update([
            'ai_enabled' => $enabled,
            'status' => $enabled ? ConversationStatus::Bot : ConversationStatus::Human,
        ]);

        return back();
    }

    private function list()
    {
        return Conversation::with('lead:id,uuid,name,stage')
            ->whereNotNull('last_message_at')
            ->orderByDesc('last_message_at')->limit(60)->get()
            ->map(fn (Conversation $c) => [
                'id' => $c->id,
                'name' => $c->contact_name ?? $c->contact_phone,
                'preview' => $c->last_message_preview,
                'at' => $c->last_message_at?->diffForHumans(),
                'unread' => $c->unread_count,
                'is_group' => $c->is_group,
                'ai_enabled' => $c->ai_enabled,
                'stage' => $c->lead?->stage?->label(),
            ]);
    }
}
