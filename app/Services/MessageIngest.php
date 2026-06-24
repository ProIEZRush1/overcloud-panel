<?php

namespace App\Services;

use App\Enums\LeadStage;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Enums\PaymentStatus;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Models\PaymentProof;
use App\Models\WhatsAppAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Turns a gateway inbound payload into Conversation / Message / Lead / PaymentProof records. */
class MessageIngest
{
    public function handle(array $payload): ?Message
    {
        $sessionName = $payload['session'] ?? null;
        $data = $payload['message'] ?? [];
        $account = WhatsAppAccount::where('session_name', $sessionName)->first();
        if (! $account || empty($data['chat_jid'])) {
            return null;
        }

        $isGroup = (bool) ($data['is_group'] ?? false);
        $fromMe = (bool) ($data['from_me'] ?? false);
        $chatJid = $data['chat_jid'];

        $conversation = Conversation::firstOrNew([
            'whatsapp_account_id' => $account->id,
            'contact_jid' => $chatJid,
        ]);
        // New threads inherit the account default; with auto_reply off the bot stays
        // silent for everyone until a thread is explicitly enabled.
        if (! $conversation->exists) {
            $conversation->ai_enabled = (bool) $account->auto_reply;
        }
        $conversation->is_group = $isGroup;
        $conversation->contact_phone ??= $isGroup ? null : Str::before($chatJid, '@');
        if (! $fromMe && ! $isGroup && empty($conversation->contact_name) && ! empty($data['push_name'])) {
            $conversation->contact_name = $data['push_name'];
        }
        $conversation->save();

        $type = MessageType::tryFrom($data['type'] ?? 'text') ?? MessageType::Text;
        $mediaPath = $this->storeMedia($account->session_name, $data);

        $message = $conversation->messages()->create([
            'wa_message_id' => $data['wa_message_id'] ?? null,
            'direction' => $fromMe ? MessageDirection::Out : MessageDirection::In,
            'type' => $type,
            'sender_jid' => $data['sender_jid'] ?? null,
            'body' => $data['text'] ?? null,
            'caption' => $data['caption'] ?? null,
            'media_path' => $mediaPath,
            'media_mime' => $data['media']['mimetype'] ?? null,
            'media_filename' => $data['media']['fileName'] ?? null,
            'status' => MessageStatus::Delivered,
            'is_from_me' => $fromMe,
            'wa_timestamp' => isset($data['timestamp']) ? Carbon::createFromTimestamp($data['timestamp']) : now(),
        ]);

        $preview = $data['text'] ?? $data['caption'] ?? '['.$type->value.']';
        $conversation->forceFill([
            'last_message_at' => $message->wa_timestamp,
            'last_message_preview' => Str::limit($preview, 120),
        ]);
        if (! $fromMe) {
            $conversation->increment('unread_count');
        }
        $conversation->save();

        if (! $isGroup && ! $fromMe) {
            $lead = $this->ensureLead($conversation, $account, $data);
            $this->maybeAttachProof($lead, $message, $type);
        }

        return $message;
    }

    protected function ensureLead(Conversation $conversation, WhatsAppAccount $account, array $data): Lead
    {
        if ($conversation->lead_id) {
            $lead = $conversation->lead;
            $lead->update(['last_contact_at' => now()]);

            return $lead;
        }

        $lead = Lead::create([
            'whatsapp_account_id' => $account->id,
            'name' => $data['push_name'] ?? null,
            'phone' => $conversation->contact_phone ?? Str::before($conversation->contact_jid, '@'),
            'source' => 'whatsapp',
            'stage' => LeadStage::New,
            'locale' => 'es',
            'last_contact_at' => now(),
        ]);
        $conversation->update(['lead_id' => $lead->id]);

        return $lead;
    }

    protected function maybeAttachProof(Lead $lead, Message $message, MessageType $type): void
    {
        if (! $type->couldBeProof() || ! $message->media_path) {
            return;
        }
        $request = $lead->paymentRequests()
            ->whereIn('status', [PaymentStatus::Pending->value, PaymentStatus::ProofSubmitted->value])
            ->latest()->first();
        if (! $request) {
            return;
        }

        PaymentProof::create([
            'payment_request_id' => $request->id,
            'message_id' => $message->id,
            'file_path' => $message->media_path,
            'file_mime' => $message->media_mime,
            'file_name' => $message->media_filename,
            'status' => PaymentStatus::ProofSubmitted,
            'submitted_at' => now(),
        ]);
        $request->update(['status' => PaymentStatus::ProofSubmitted]);
    }

    protected function storeMedia(string $session, array $data): ?string
    {
        $media = $data['media'] ?? null;
        if (! $media || empty($media['base64'])) {
            return null;
        }
        $ext = Str::of($media['fileName'] ?? '')->afterLast('.')->limit(8, '')->value()
            ?: Str::of($media['mimetype'] ?? 'bin')->afterLast('/')->limit(8, '')->value();
        $path = "wa/{$session}/".($data['wa_message_id'] ?? Str::uuid()).'.'.($ext ?: 'bin');
        Storage::put($path, base64_decode($media['base64']));

        return $path;
    }
}
