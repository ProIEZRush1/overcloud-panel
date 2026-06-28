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
        return $this->postSend($session, array_filter([
            'to' => $to, 'text' => $text, 'quoted' => $quoted,
        ]));
    }

    /** $media = ['base64'=>..., 'mimetype'=>..., 'fileName'=>..., 'caption'=>..., 'kind'=>'image|document|audio|video'] */
    public function sendMedia(string $session, string $to, array $media): array
    {
        return $this->postSend($session, ['to' => $to, 'media' => $media]);
    }

    /**
     * Send a native in-chat interactive menu. $spec:
     *   ['body'=>'...', 'footer'=>'...', 'button'=>'Ver opciones',
     *    'sections'=>[['title'=>'', 'rows'=>[['title'=>'', 'description'=>'', 'id'=>'']]]],
     *    'buttons'=>[['type'=>'quick_reply'|'cta_url', 'text'=>'', 'id'=>'', 'url'=>'']]]
     */
    public function sendInteractive(string $session, string $to, array $spec): array
    {
        return $this->postSend($session, ['to' => $to, 'interactive' => $spec]);
    }

    /**
     * POST a send and retry until WhatsApp actually confirms it (returns a wa_message_id).
     * Baileys can answer HTTP 200 with ok:false / no id (accepted but NOT delivered), which
     * silently dropped demo links and replies — so we retry instead of trusting the 200.
     */
    private function postSend(string $session, array $payload): array
    {
        $last = ['ok' => false];
        for ($i = 0; $i < 3; $i++) {
            try {
                $r = (array) $this->client()->post("/sessions/{$session}/send", $payload)->throw()->json();
                if (! empty($r['wa_message_id'])) {
                    return $r;
                }
                $last = $r ?: $last;
            } catch (\Throwable $e) {
                $last = ['ok' => false, 'error' => $e->getMessage()];
            }
            usleep(1500000); // 1.5s before retrying a transient gateway/Baileys hiccup
        }

        return $last;
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

    /** Get the join-by-link URL for a group (so members join themselves — avoids add-restrictions). */
    public function groupInvite(string $session, string $groupJid): ?string
    {
        try {
            return $this->client()->get("/sessions/{$session}/group/".urlencode($groupJid).'/invite')->json('url');
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function sendPresence(string $session, string $to, string $type = 'composing'): void
    {
        $this->client()->post("/sessions/{$session}/presence", ['to' => $to, 'type' => $type]);
    }
}
