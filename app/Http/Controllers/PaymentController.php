<?php

namespace App\Http\Controllers;

use App\Enums\LeadStage;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Models\PaymentProposal;
use App\Models\PaymentRequest;
use App\Services\BillingService;
use App\Services\BotResponder;
use App\Services\CrmSync;
use App\Services\DeployService;
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

        $proposals = PaymentProposal::with('lead:id,name,phone,company')
            ->orderByRaw("CASE status WHEN 'pending' THEN 0 ELSE 1 END")->latest()->get()
            ->map(fn (PaymentProposal $p) => [
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
            if (in_array($payment->type, [PaymentType::Deposit, PaymentType::Full], true) && $payment->quote) {
                if ($payment->type === PaymentType::Full) {
                    $payment->lead?->update(['stage' => LeadStage::Paid]);
                }
                $project = app(ProjectService::class)->provisionFromQuote($payment->quote);

                // New flow: the client already had the full DEMO live (locked). The anticipo UNLOCKS it
                // (flip TRIAL_LOCKED + redeploy) + makes it permanent — no rebuild.
                $wasLockedDemo = ($project->brief['demo'] ?? false) && $project->coolify_app_uuid && empty($project->brief['paid']);
                if ($wasLockedDemo) {
                    $project->update([
                        'brief' => array_merge((array) $project->brief, ['paid' => true]),
                        'maintenance_active' => true,
                        'delivered_at' => $project->delivered_at ?? now(),
                    ]);
                    app(DeployService::class)->unlockTrial($project);
                    $conv = $payment->lead?->conversations()->where('is_group', false)->first();
                    if ($conv && $conv->whatsappAccount) {
                        app(WhatsAppGateway::class)->sendText($conv->whatsappAccount->session_name, $conv->contact_jid,
                            '¡Anticipo recibido! 🎉 Acabo de *activar todo* tu sistema — ya puedes *cobrar y vender*. Es tuyo y queda fijo. En un par de minutos verás todo desbloqueado. 🙌');
                    }
                } elseif (config('overcloud.deploy.enabled')) {
                    app(BotResponder::class)->startGathering($project);
                } else {
                    $conv = $payment->lead?->conversations()->where('is_group', false)->first();
                    if ($conv && $conv->whatsappAccount) {
                        app(WhatsAppGateway::class)->sendText($conv->whatsappAccount->session_name, $conv->contact_jid,
                            '¡Tu pago quedó verificado! ✅ Ya arrancamos con tu proyecto; cualquier cambio o duda, por aquí. 🚀');
                    }
                }
            } elseif (in_array($payment->type, [PaymentType::Balance, PaymentType::Maintenance], true)) {
                // Milestone / maintenance verified → resume the site + bill the next step.
                app(BillingService::class)->onPaymentVerified($payment);
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
