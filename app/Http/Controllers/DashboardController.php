<?php

namespace App\Http\Controllers;

use App\Enums\LeadStage;
use App\Enums\PaymentStatus;
use App\Enums\ProjectStatus;
use App\Enums\QuoteStatus;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\PaymentRequest;
use App\Models\Project;
use App\Models\Quote;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function __invoke()
    {
        $byStage = Lead::query()
            ->selectRaw('stage, count(*) as c')
            ->groupBy('stage')->pluck('c', 'stage');

        $pipeline = collect(LeadStage::cases())
            ->reject(fn (LeadStage $s) => $s === LeadStage::Lost)
            ->map(fn (LeadStage $s) => [
                'stage' => $s->value,
                'label' => $s->label(),
                'color' => $s->color(),
                'count' => (int) ($byStage[$s->value] ?? 0),
            ])->values();

        return Inertia::render('Dashboard', [
            'stats' => [
                'leads_open' => Lead::whereNotIn('stage', [LeadStage::Delivered->value, LeadStage::Lost->value])->count(),
                'quotes_sent' => Quote::where('status', QuoteStatus::Sent)->count(),
                'payments_to_review' => PaymentRequest::where('status', PaymentStatus::ProofSubmitted)->count(),
                'active_projects' => Project::whereIn('status', [ProjectStatus::Live->value, ProjectStatus::Maintenance->value, ProjectStatus::Building->value])->count(),
                'mrr_cents' => (int) \App\Models\Subscription::where('status', 'active')->sum('monthly_price_cents'),
            ],
            'pipeline' => $pipeline,
            'recent' => Conversation::with('lead:id,uuid,name,stage')
                ->whereNotNull('last_message_at')
                ->latest('last_message_at')->limit(8)->get()
                ->map(fn (Conversation $c) => [
                    'id' => $c->id,
                    'name' => $c->contact_name ?? $c->contact_phone,
                    'preview' => $c->last_message_preview,
                    'at' => $c->last_message_at?->diffForHumans(),
                    'unread' => $c->unread_count,
                    'lead_uuid' => $c->lead?->uuid,
                ]),
        ]);
    }
}
