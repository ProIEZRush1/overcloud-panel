<?php

namespace App\Http\Controllers;

use App\Enums\LeadStage;
use App\Models\BankAccount;
use App\Models\Lead;
use App\Models\MaintenancePlan;
use App\Models\Quote;
use App\Models\Service;
use App\Models\ServiceFeature;
use App\Models\Spec;
use App\Services\PdfService;
use App\Services\QuoteBuilder;
use App\Services\SpecBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class LeadController extends Controller
{
    public function index(Request $request)
    {
        $leads = Lead::with('service:id,name', 'latestQuote')
            ->when($request->string('stage')->value(), fn ($q, $s) => $q->where('stage', $s))
            ->orderByDesc('last_contact_at')->orderByDesc('id')->get()
            ->map(fn (Lead $l) => [
                'uuid' => $l->uuid,
                'name' => $l->name ?? $l->phone,
                'phone' => $l->phone,
                'company' => $l->company,
                'stage' => $l->stage->value,
                'stage_label' => $l->stage->label(),
                'stage_color' => $l->stage->color(),
                'service' => $l->service?->name,
                'quote_total' => $l->latestQuote ? \App\Support\Money::format($l->latestQuote->total_cents) : null,
                'updated' => $l->last_contact_at?->diffForHumans(),
            ]);

        return Inertia::render('leads/Index', [
            'leads' => $leads,
            'stages' => collect(LeadStage::cases())->map(fn ($s) => ['value' => $s->value, 'label' => $s->label(), 'color' => $s->color()]),
            'filter' => $request->string('stage')->value(),
        ]);
    }

    public function show(Lead $lead)
    {
        $lead->load(['service', 'maintenancePlan', 'conversations:id,lead_id,contact_name,contact_phone',
            'specs' => fn ($q) => $q->latest(), 'quotes' => fn ($q) => $q->with('items')->latest(),
            'paymentRequests' => fn ($q) => $q->with('latestProof')->latest(), 'project']);

        return Inertia::render('leads/Show', [
            'lead' => $this->presentLead($lead),
            'specs' => $lead->specs->map(fn (Spec $s) => [
                'uuid' => $s->uuid, 'version' => $s->version, 'title' => $s->title,
                'status' => $s->status->value, 'status_label' => $s->status->label(),
                'pdf_url' => $s->pdf_path ? Storage::url($s->pdf_path) : null,
                'created' => $s->created_at->format('d/m/Y'),
            ]),
            'quotes' => $lead->quotes->map(fn (Quote $q) => $this->presentQuote($q)),
            'payments' => $lead->paymentRequests->map(fn ($p) => [
                'id' => $p->id, 'type' => $p->type->value, 'type_label' => $p->type->label(),
                'amount' => \App\Support\Money::format($p->amount_cents, $p->currency),
                'status' => $p->status->value, 'status_label' => $p->status->label(),
                'reference' => $p->reference,
                'proof_url' => $p->latestProof?->file_path ? Storage::url($p->latestProof->file_path) : null,
            ]),
            'project' => $lead->project ? ['uuid' => $lead->project->uuid, 'name' => $lead->project->name, 'status' => $lead->project->status->value] : null,
            'options' => [
                'services' => Service::where('is_active', true)->orderBy('sort_order')->get(['id', 'key', 'name', 'base_price_cents']),
                'features' => ServiceFeature::where('is_active', true)->orderBy('sort_order')->get(['id', 'name', 'price_cents']),
                'plans' => MaintenancePlan::where('is_active', true)->orderBy('sort_order')->get(['id', 'name', 'monthly_price_cents']),
                'stages' => collect(LeadStage::cases())->map(fn ($s) => ['value' => $s->value, 'label' => $s->label()]),
                'banks' => BankAccount::where('is_active', true)->count(),
            ],
        ]);
    }

    public function update(Request $request, Lead $lead)
    {
        $data = $request->validate([
            'name' => 'nullable|string|max:120', 'email' => 'nullable|email', 'company' => 'nullable|string|max:120',
            'stage' => 'nullable|string', 'service_id' => 'nullable|exists:services,id',
            'maintenance_plan_id' => 'nullable|exists:maintenance_plans,id',
            'pages' => 'nullable|integer|min:1', 'languages' => 'nullable|array',
            'deposit_percent' => 'nullable|integer|min:0|max:100', 'summary' => 'nullable|string', 'notes' => 'nullable|string',
        ]);
        $lead->update($data);

        return back()->with('success', 'Lead actualizado.');
    }

    public function spec(Lead $lead, SpecBuilder $builder, PdfService $pdf)
    {
        $spec = $builder->buildFromLead($lead);
        $pdf->renderSpec($spec);

        return back()->with('success', 'Alcance generado.');
    }

    public function quote(Request $request, Lead $lead, QuoteBuilder $builder, PdfService $pdf)
    {
        $opts = $request->validate([
            'feature_ids' => 'nullable|array', 'feature_ids.*' => 'integer',
            'pages' => 'nullable|integer|min:1', 'languages' => 'nullable|integer|min:1',
            'discount_cents' => 'nullable|integer|min:0',
        ]);
        $quote = $builder->buildFromLead($lead, null, $opts);
        $pdf->renderQuote($quote);

        return back()->with('success', "Cotización {$quote->number} generada.");
    }

    private function presentLead(Lead $lead): array
    {
        return [
            'uuid' => $lead->uuid, 'name' => $lead->name, 'phone' => $lead->phone, 'email' => $lead->email,
            'company' => $lead->company, 'stage' => $lead->stage->value, 'stage_label' => $lead->stage->label(),
            'stage_color' => $lead->stage->color(), 'service_id' => $lead->service_id, 'service' => $lead->service?->name,
            'maintenance_plan_id' => $lead->maintenance_plan_id, 'pages' => $lead->pages,
            'languages' => $lead->languages ?? ['es'], 'deposit_percent' => $lead->deposit_percent,
            'summary' => $lead->summary, 'notes' => $lead->notes, 'score' => $lead->score,
            'conversation_id' => $lead->conversations->first()?->id,
        ];
    }

    private function presentQuote(Quote $q): array
    {
        return [
            'uuid' => $q->uuid, 'number' => $q->number, 'status' => $q->status->value, 'status_label' => $q->status->label(),
            'total' => \App\Support\Money::format($q->total_cents, $q->currency),
            'deposit' => \App\Support\Money::format($q->deposit_cents, $q->currency),
            'maintenance' => $q->maintenance_monthly_cents ? \App\Support\Money::format($q->maintenance_monthly_cents, $q->currency) : null,
            'valid_until' => $q->valid_until?->format('d/m/Y'),
            'pdf_url' => $q->pdf_path ? Storage::url($q->pdf_path) : null,
            'items' => $q->items->map(fn ($i) => [
                'description' => $i->description, 'quantity' => $i->quantity,
                'total' => \App\Support\Money::format($i->total_cents, $q->currency),
            ]),
        ];
    }
}
