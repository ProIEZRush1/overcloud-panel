<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Thin client for the Node Baileys gateway. Each WhatsApp number is a "session"
 * identified by its session_name.
 */
class WhatsAppGateway
{
    protected function client(): PendingRequest
    {
        return Http::baseUrl(config('overcloud.gateway.url'))
            ->withHeaders(['X-Gateway-Token' => config('overcloud.gateway.token')])
            ->timeout(30);
    }

    public function listSessions(): array
    {
        return $this->client()->get('/sessions')->json() ?? [];
    }

    public function connect(string $session): array
    {
        return $this->client()->post("/sessions/{$session}/connect")->throw()->json();
    }

    public function status(string $session): ?array
    {
        $res = $this->client()->get("/sessions/{$session}");

        return $res->successful() ? $res->json() : null;
    }

    public function qr(string $session): ?array
    {
        $res = $this->client()->get("/sessions/{$session}/qr");

        return $res->successful() ? $res->json() : null;
    }

    public function logout(string $session): void
    {
        $this->client()->post("/sessions/{$session}/logout");
    }

    public function remove(string $session): void
    {
        $this->client()->delete("/sessions/{$session}");
    }

    public function sendText(string $session, string $to, string $text, ?string $quoted = null): array
    {
        return $this->client()->post("/sessions/{$session}/send", array_filter([
            'to' => $to, 'text' => $text, 'quoted' => $quoted,
        ]))->throw()->json();
    }

    /** $media = ['base64'=>..., 'mimetype'=>..., 'fileName'=>..., 'caption'=>..., 'kind'=>'image|document|audio|video'] */
    public function sendMedia(string $session, string $to, array $media): array
    {
        return $this->client()->post("/sessions/{$session}/send", [
            'to' => $to, 'media' => $media,
        ])->throw()->json();
    }

    public function createGroup(string $session, string $subject, array $participants): array
    {
        return $this->client()->post("/sessions/{$session}/group", [
            'subject' => $subject, 'participants' => $participants,
        ])->throw()->json();
    }

    public function updateParticipants(string $session, string $groupJid, array $participants, string $action): array
    {
        return $this->client()->post("/sessions/{$session}/group/participants", [
            'jid' => $groupJid, 'participants' => $participants, 'action' => $action,
        ])->throw()->json();
    }

    public function sendPresence(string $session, string $to, string $type = 'composing'): void
    {
        $this->client()->post("/sessions/{$session}/presence", ['to' => $to, 'type' => $type]);
    }
}
