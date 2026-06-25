<?php

namespace App\Http\Controllers\Api;

use App\Enums\MessageStatus;
use App\Enums\WhatsAppAccountStatus;
use App\Http\Controllers\Controller;
use App\Jobs\GenerateBotReply;
use App\Models\Message;
use App\Models\WhatsAppAccount;
use App\Services\MessageIngest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class WhatsAppWebhookController extends Controller
{
    public function inbound(Request $request, MessageIngest $ingest)
    {
        $message = $ingest->handle($request->all());

        // Queue the AI reply so the Claude Code CLI runs in the worker's
        // login-shell context (PATH + keychain), never blocking the webhook.
        if ($message && $message->isInbound() && $message->wasRecentlyCreated) {
            GenerateBotReply::dispatch($message->id);
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
