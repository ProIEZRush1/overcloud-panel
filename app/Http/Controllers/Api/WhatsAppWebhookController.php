<?php

namespace App\Http\Controllers\Api;

use App\Enums\MessageStatus;
use App\Enums\WhatsAppAccountStatus;
use App\Http\Controllers\Controller;
use App\Jobs\GenerateBotReply;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\WhatsAppAccount;
use App\Services\MessageIngest;
use App\Services\WhatsAppGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class WhatsAppWebhookController extends Controller
{
    public function inbound(Request $request, MessageIngest $ingest)
    {
        $message = $ingest->handle($request->all());

        // Queue the AI reply so the Claude Code CLI runs in the worker's
        // login-shell context (PATH + keychain), never blocking the webhook.
        if ($message && $message->isInbound() && $message->wasRecentlyCreated) {
            // Small delay so rapid fragmented messages accumulate; the job replies once to the latest.
            GenerateBotReply::dispatch($message->id)->delay(now()->addSeconds(7));
        }

        return response()->json(['ok' => true, 'message_id' => $message?->id]);
    }

    public function status(Request $request)
    {
        $session = $request->string('session');
        $account = WhatsAppAccount::where('session_name', $session)->first();
        if (! $account) {
            return response()->json(['ok' => false], 404);
        }

        $status = WhatsAppAccountStatus::tryFrom($request->string('status'));
        if ($status) {
            $account->status = $status;
        }
        if ($request->filled('jid')) {
            $account->jid = $request->string('jid');
        }
        if ($request->filled('phone')) {
            $account->phone = $request->string('phone');
        }
        if ($status === WhatsAppAccountStatus::Connected) {
            $account->last_connected_at = now();
        }
        $account->save();

        // Cache the QR for the connect page to poll.
        if ($request->filled('qr')) {
            Cache::put("wa:qr:{$session}", $request->string('qr')->value(), now()->addMinutes(2));
        } elseif ($status && $status !== WhatsAppAccountStatus::QrPending) {
            Cache::forget("wa:qr:{$session}");
        }

        return response()->json(['ok' => true]);
    }

    /**
     * A participant joined/left a project group. When a real client joins (not the owner/bot),
     * send the welcome — because WhatsApp doesn't show messages posted before they joined.
     */
    public function groupEvent(Request $request, WhatsAppGateway $gateway)
    {
        if ($request->string('action')->value() !== 'add') {
            return response()->json(['ok' => true]);
        }
        $session = $request->string('session')->value();
        $groupJid = $request->string('group_jid')->value();
        $account = WhatsAppAccount::where('session_name', $session)->first();
        if (! $account || ! $groupJid) {
            return response()->json(['ok' => true]);
        }
        $conv = Conversation::where('whatsapp_account_id', $account->id)
            ->where('contact_jid', $groupJid)->where('is_group', true)->with('lead')->first();
        if (! $conv) {
            return response()->json(['ok' => true]);
        }

        $digits = fn ($v) => preg_replace('/\D/', '', (string) $v);
        $ownerNum = $digits(config('overcloud.company.owner_phone'));
        $botNum = $digits($account->phone);
        $meta = (array) ($conv->meta ?? []);
        $welcomed = (array) ($meta['welcomed_participants'] ?? []);

        // New, real joiners (not owner, not bot, not already welcomed).
        $fresh = [];
        foreach ((array) $request->input('participants', []) as $p) {
            $num = $digits($p);
            if ($num === '' || $num === $ownerNum || $num === $botNum || in_array($num, $welcomed, true)) {
                continue;
            }
            $fresh[] = $num;
        }
        if (empty($fresh)) {
            return response()->json(['ok' => true]);
        }

        $subject = $conv->contact_name ?: ($conv->lead?->name ? 'el proyecto de '.$conv->lead->name : 'tu proyecto');
        $welcome = "¡Bienvenido a tu grupo de proyecto en *Overcloud*! 🚀\n\n"
            ."Aquí coordinamos *{$subject}*. Escríbeme cualquier cambio, duda o material y lo atiendo al momento. "
            .'Los ajustes dentro del alcance acordado son sin costo; si algo se sale del alcance, te aviso antes de hacerlo. ✨';
        try {
            $gateway->sendText($session, $groupJid, $welcome);
            $meta['welcomed_participants'] = array_values(array_unique(array_merge($welcomed, $fresh)));
            $conv->meta = $meta;
            $conv->save();
        } catch (\Throwable $e) {
            Log::warning('group welcome failed', ['group' => $groupJid, 'e' => $e->getMessage()]);
        }

        return response()->json(['ok' => true]);
    }

    public function receipt(Request $request)
    {
        $waId = $request->string('wa_message_id')->value();
        if (! $waId) {
            return response()->json(['ok' => true]);
        }
        $status = match ((int) $request->integer('status')) {
            3 => MessageStatus::Delivered,
            4 => MessageStatus::Read,
            default => null,
        };
        if ($status) {
            Message::where('wa_message_id', $waId)->update(['status' => $status]);
        }

        return response()->json(['ok' => true]);
    }
}
