<?php

namespace App\Http\Controllers;

use App\Enums\WhatsAppAccountStatus;
use App\Models\WhatsAppAccount;
use App\Services\WhatsAppGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Inertia\Inertia;

class WhatsAppAccountController extends Controller
{
    public function __construct(private WhatsAppGateway $gateway) {}

    public function index()
    {
        return Inertia::render('whatsapp/Index', [
            'accounts' => WhatsAppAccount::orderByDesc('is_default')->orderBy('label')->get()
                ->map(fn (WhatsAppAccount $a) => $this->present($a)),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['label' => 'required|string|max:60']);
        $account = WhatsAppAccount::create([
            'label' => $data['label'],
            'session_name' => Str::slug($data['label']).'-'.Str::lower(Str::random(4)),
            'status' => WhatsAppAccountStatus::Disconnected,
            'is_default' => WhatsAppAccount::count() === 0,
        ]);
        $this->safe(fn () => $this->gateway->connect($account->session_name));
        $account->update(['status' => WhatsAppAccountStatus::Connecting]);

        return back()->with('success', 'Número creado. Escanea el código QR para conectarlo.');
    }

    public function connect(WhatsAppAccount $account)
    {
        $this->safe(fn () => $this->gateway->connect($account->session_name));
        $account->update(['status' => WhatsAppAccountStatus::Connecting]);

        return back();
    }

    /** Polled by the connect modal for live status + QR. */
    public function status(WhatsAppAccount $account)
    {
        return response()->json([
            'status' => $account->status->value,
            'status_label' => $account->status->label(),
            'phone' => $account->phone,
            'qr' => Cache::get("wa:qr:{$account->session_name}"),
        ]);
    }

    public function logout(WhatsAppAccount $account)
    {
        $this->safe(fn () => $this->gateway->logout($account->session_name));
        $account->update(['status' => WhatsAppAccountStatus::LoggedOut, 'phone' => null, 'jid' => null]);

        return back()->with('success', 'Sesión cerrada.');
    }

    public function destroy(WhatsAppAccount $account)
    {
        $this->safe(fn () => $this->gateway->remove($account->session_name));
        $account->delete();

        return back()->with('success', 'Número eliminado.');
    }

    private function present(WhatsAppAccount $a): array
    {
        return [
            'id' => $a->id,
            'label' => $a->label,
            'session_name' => $a->session_name,
            'phone' => $a->phone,
            'status' => $a->status->value,
            'status_label' => $a->status->label(),
            'is_default' => $a->is_default,
            'auto_reply' => $a->auto_reply,
            'last_connected_at' => $a->last_connected_at?->diffForHumans(),
        ];
    }

    private function safe(callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
