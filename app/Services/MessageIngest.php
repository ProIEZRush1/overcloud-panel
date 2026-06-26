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
use Illuminate\Database\UniqueConstraintViolationException;
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
        // New DM threads inherit the account default; groups stay bot-OFF by default
        // (only project groups, enabled explicitly on payment, get the bot).
        if (! $conversation->exists) {
            $conversation->ai_enabled = ! $isGroup && (bool) $account->auto_reply;
        }
        $conversation->is_group = $isGroup;
        $conversation->contact_phone ??= $isGroup ? null : Str::before($chatJid, '@');
        if (! $fromMe && ! $isGroup && empty($conversation->contact_name) && ! empty($data['push_name'])) {
            $conversation->contact_name = $data['push_name'];
        }
        $conversation->save();

        $type = MessageType::tryFrom($data['type'] ?? 'text') ?? MessageType::Text;
        $mediaPath = $this->storeMedia($account->session_name, $data);

        $attrs = [
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
        ];
        $waId = $data['wa_message_id'] ?? null;

        // Dedup duplicate webhooks: the same wa_message_id must yield ONE row (and one reply).
        // Backed by a UNIQUE index, so even a concurrent double-POST collapses to one — if we lose
        // the insert race we fetch the row the other request created and treat it as already ingested.
        if ($waId) {
            try {
                $message = $conversation->messages()->firstOrCreate(['wa_message_id' => $waId], $attrs);
            } catch (UniqueConstraintViolationException $e) {
                $message = $conversation->messages()->where('wa_message_id', $waId)->first();
                if (! $message) {
                    throw $e;
                }
            }
        } else {
            $message = $conversation->messages()->create($attrs + ['wa_message_id' => null]);
        }
        if (! $message->wasRecentlyCreated) {
            return $message; // already ingested → skip preview/unread/lead/proof and re-reply
        }

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
        // Attach to the request actually awaiting payment: prefer the latest PENDING one (the deposit
        // the client is paying now), not whatever request happens to be newest — deposit, milestones
        // and maintenance can all be open at once and a "latest()" could mark the wrong one paid.
        $request = $lead->paymentRequests()
            ->where('status', PaymentStatus::Pending->value)
            ->latest('id')->first()
            ?? $lead->paymentRequests()
                ->where('status', PaymentStatus::ProofSubmitted->value)
                ->latest('id')->first();
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
