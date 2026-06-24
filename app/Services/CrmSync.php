<?php

namespace App\Services;

use App\Models\PaymentRequest;
use App\Models\Quote;

/**
 * Orchestrates pushing Overcloud funnel state into the Dev-Business hub at the
 * key milestones. All calls are best-effort (DevBusinessClient never throws).
 */
class CrmSync
{
    public function __construct(private DevBusinessClient $devbiz) {}

    /** Quote accepted: upsert the client, the project, and (if any) the maintenance service. */
    public function syncQuoteAccepted(Quote $quote): void
    {
        if (! $this->devbiz->isEnabled()) {
            return;
        }

        $lead = $quote->lead;
        $lead->loadMissing('service', 'maintenancePlan', 'conversations');
        $jid = $lead->conversations->first()?->contact_jid;

        $client = $this->devbiz->upsertClient([
            'external_ref' => $lead->uuid,
            'name' => $lead->name ?: $lead->phone,
            'email' => $lead->email,
            'phone_country_code' => '+52',
            'phone' => $this->last10($lead->phone),
            'whatsapp_jid' => $jid,
            'status' => 'Active',
        ]);
        if ($client && isset($client['id'])) {
            $lead->crm_client_id = $client['id'];
            $lead->saveQuietly();
        }

        $project = $this->devbiz->upsertProject([
            'external_ref' => $quote->uuid,
            'client_external_ref' => $lead->uuid,
            'client_phone' => $lead->phone,
            'name' => trim(($lead->service?->name ?? 'Proyecto').' · '.$quote->number),
            'amount' => $quote->total_cents / 100,
            'currency' => $quote->currency,
            'status' => 'In Progress',
        ]);
        if ($project && isset($project['id'])) {
            $quote->crm_project_id = $project['id'];
            $quote->saveQuietly();
        }

        if ($quote->maintenance_monthly_cents > 0) {
            $this->devbiz->upsertService([
                'external_ref' => $quote->uuid.':maintenance',
                'client_external_ref' => $lead->uuid,
                'client_phone' => $lead->phone,
                'name' => $quote->maintenancePlan?->name ?? ('Mantenimiento · '.$quote->number),
                'monthly_amount' => $quote->maintenance_monthly_cents / 100,
                'currency' => $quote->currency,
                'billing_day' => 1,
                'status' => 'Active',
            ]);
        }
    }

    /** Payment verified: record it against the project in the hub. */
    public function syncPaymentVerified(PaymentRequest $request): void
    {
        if (! $this->devbiz->isEnabled()) {
            return;
        }

        $request->loadMissing('quote');

        $this->devbiz->createPayment([
            'external_ref' => 'oc-payment-'.$request->id,
            'project_external_ref' => $request->quote?->uuid,
            'amount' => $request->amount_cents / 100,
            'currency' => $request->currency,
            'payment_method' => 'Bank Transfer',
            'payment_date' => now()->toDateString(),
            'status' => 'Completed',
        ]);
    }

    private function last10(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);

        return $digits !== '' ? substr($digits, -10) : null;
    }
}
