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

        $proposals = \App\Models\PaymentProposal::with('lead:id,name,phone,company')
            ->orderByRaw("CASE status WHEN 'pending' THEN 0 ELSE 1 END")->latest()->get()
            ->map(fn (\App\Models\PaymentProposal $p) => [
                'id' => $p->id,
                'client' => $p->lead?->company ?: ($p->lead?->name ?? $p->lead?->phone),
                'proposal' => $p->proposal,
                'status' => $p->status,
                'created' => $p->created_at->diffForHumans(),
            ]);

        return Inertia::render('payments/Index', [
            'requests' => $requests,
            'to_review' => $requests->where('status', PaymentStatus::ProofSubmitted->value)->count(),
            'proposals' => $proposals,
            'pending_proposals' => $proposals->where('status', 'pending')->count(),
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

        try {
            // Deposit OR full upfront payment → spin up the project and start gathering.
            if (in_array($payment->type, [\App\Enums\PaymentType::Deposit, \App\Enums\PaymentType::Full], true) && $payment->quote) {
                if ($payment->type === \App\Enums\PaymentType::Full) {
                    $payment->lead?->update(['stage' => \App\Enums\LeadStage::Paid]);
                }
                $project = app(ProjectService::class)->provisionFromQuote($payment->quote);

                if (config('overcloud.deploy.enabled')) {
                    app(BotResponder::class)->startGathering($project);
                } else {
                    $conv = $payment->lead?->conversations()->where('is_group', false)->first();
                    if ($conv && $conv->whatsappAccount) {
                        app(WhatsAppGateway::class)->sendText($conv->whatsappAccount->session_name, $conv->contact_jid,
                            '¡Tu pago quedó verificado! ✅ Ya arrancamos con tu proyecto; cualquier cambio o duda, por aquí. 🚀');
                    }
                }
            } elseif (in_array($payment->type, [\App\Enums\PaymentType::Balance, \App\Enums\PaymentType::Maintenance], true)) {
                // Milestone / maintenance verified → resume the site + bill the next step.
                app(\App\Services\BillingService::class)->onPaymentVerified($payment);
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
