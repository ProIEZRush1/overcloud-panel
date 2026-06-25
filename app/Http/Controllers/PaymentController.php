<?php

namespace App\Http\Controllers;

use App\Enums\PaymentStatus;
use App\Models\PaymentRequest;
use App\Services\BotResponder;
use App\Services\CrmSync;
use App\Services\PaymentService;
use App\Services\ProjectService;
use App\Services\WhatsAppGateway;
use App\Support\Money;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PaymentController extends Controller
{
    public function index()
    {
        $requests = PaymentRequest::with(['lead:id,uuid,name,phone', 'latestProof'])
            ->orderByRaw("CASE status WHEN 'proof_submitted' THEN 0 WHEN 'pending' THEN 1 WHEN 'verified' THEN 2 ELSE 3 END")
            ->latest()->get()
            ->map(fn (PaymentRequest $p) => [
                'id' => $p->id,
                'lead' => ['uuid' => $p->lead?->uuid, 'name' => $p->lead?->name ?? $p->lead?->phone],
                'type' => $p->type->value, 'type_label' => $p->type->label(),
                'amount' => Money::format($p->amount_cents, $p->currency),
                'status' => $p->status->value, 'status_label' => $p->status->label(),
                'reference' => $p->reference,
                'proof_url' => $p->latestProof ? route('files.proof', $p->latestProof) : null,
                'proof_mime' => $p->latestProof?->file_mime,
                'created' => $p->created_at->diffForHumans(),
            ]);

        return Inertia::render('payments/Index', [
            'requests' => $requests,
            'to_review' => $requests->where('status', PaymentStatus::ProofSubmitted->value)->count(),
        ]);
    }

    public function verify(PaymentRequest $payment, PaymentService $service, Request $request)
    {
        $service->verify($payment, $request->user()->id);

        try {
            app(CrmSync::class)->syncPaymentVerified($payment);
        } catch (\Throwable $e) {
            report($e);
        }

        // First payment verified → spin up the project + its WhatsApp group, then
        // tell the client in their chat that we're starting.
        try {
            if ($payment->quote) {
                $project = app(ProjectService::class)->provisionFromQuote($payment->quote);

                // Don't build yet: the bot first asks the client for everything it needs
                // (content, photos, accesses, or "do it all for me"), then triggers the build.
                if (config('overcloud.deploy.enabled')) {
                    app(BotResponder::class)->startGathering($project);
                } else {
                    $conv = $payment->lead?->conversations()->where('is_group', false)->first();
                    if ($conv && $conv->whatsappAccount) {
                        app(WhatsAppGateway::class)->sendText($conv->whatsappAccount->session_name, $conv->contact_jid,
                            '¡Tu pago quedó verificado! ✅ Ya arrancamos con tu proyecto; cualquier cambio o duda, por aquí. 🚀');
                    }
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return back()->with('success', 'Pago verificado. El proyecto inició. 🚀');
    }

    public function reject(PaymentRequest $payment, PaymentService $service, Request $request)
    {
        $data = $request->validate(['notes' => 'nullable|string|max:500']);
        $service->reject($payment, $data['notes'] ?? null, $request->user()->id);

        return back()->with('success', 'Pago rechazado.');
    }
}
