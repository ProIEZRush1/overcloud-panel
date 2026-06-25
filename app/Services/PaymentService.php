<?php

namespace App\Services;

use App\Enums\LeadStage;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Models\BankAccount;
use App\Models\PaymentRequest;
use App\Models\Project;
use App\Models\Quote;

class PaymentService
{
    /** Create the deposit (40%) payment request for an accepted quote. */
    public function createDeposit(Quote $quote): PaymentRequest
    {
        $bank = $this->defaultBank();

        return PaymentRequest::create([
            'lead_id' => $quote->lead_id,
            'quote_id' => $quote->id,
            'bank_account_id' => $bank?->id,
            'type' => $quote->deposit_cents > 0 ? PaymentType::Deposit : PaymentType::Full,
            'amount_cents' => $quote->deposit_cents > 0 ? $quote->deposit_cents : $quote->total_cents,
            'currency' => $quote->currency,
            'status' => PaymentStatus::Pending,
            'bank_details_snapshot' => $bank?->toSnapshot(),
            'reference' => $quote->number,
            'due_date' => now()->addDays(3),
            'sent_at' => now(),
        ]);
    }

    /** A milestone balance (e.g. 30% al desplegar, 30% final), due in $dueDays. */
    public function createBalance(Quote $quote, ?Project $project, string $reference, int $amountCents, int $dueDays = 7): PaymentRequest
    {
        $bank = $this->defaultBank();

        return PaymentRequest::create([
            'lead_id' => $quote->lead_id,
            'quote_id' => $quote->id,
            'project_id' => $project?->id,
            'bank_account_id' => $bank?->id,
            'type' => PaymentType::Balance,
            'amount_cents' => $amountCents,
            'currency' => $quote->currency,
            'status' => PaymentStatus::Pending,
            'bank_details_snapshot' => $bank?->toSnapshot(),
            'reference' => $reference,
            'due_date' => now()->addDays($dueDays),
            'sent_at' => now(),
        ]);
    }

    /** The monthly maintenance charge, due in $dueDays. */
    public function createMaintenance(Project $project, int $dueDays = 7): ?PaymentRequest
    {
        $monthly = (int) ($project->quote?->maintenance_monthly_cents ?? 0);
        if ($monthly <= 0) {
            return null;
        }
        $bank = $this->defaultBank();

        return PaymentRequest::create([
            'lead_id' => $project->lead_id,
            'quote_id' => $project->quote_id,
            'project_id' => $project->id,
            'bank_account_id' => $bank?->id,
            'type' => PaymentType::Maintenance,
            'amount_cents' => $monthly,
            'currency' => $project->quote?->currency ?? 'MXN',
            'status' => PaymentStatus::Pending,
            'bank_details_snapshot' => $bank?->toSnapshot(),
            'reference' => 'Mantenimiento '.now()->locale('es')->isoFormat('MMMM YYYY'),
            'due_date' => now()->addDays($dueDays),
            'sent_at' => now(),
        ]);
    }

    public function verify(PaymentRequest $request, ?int $userId = null): void
    {
        $request->update([
            'status' => PaymentStatus::Verified,
            'verified_at' => now(),
            'verified_by_user_id' => $userId,
        ]);
        $request->proofs()->latest()->first()?->update([
            'status' => PaymentStatus::Verified,
            'reviewed_at' => now(),
            'reviewed_by_user_id' => $userId,
        ]);

        // Only the deposit advances the lead into the post-payment (build) flow.
        if ($request->type === PaymentType::Deposit) {
            $request->lead?->update(['stage' => LeadStage::Paid]);
        }
    }

    public function reject(PaymentRequest $request, ?string $notes = null, ?int $userId = null): void
    {
        $request->update([
            'status' => PaymentStatus::Rejected,
            'rejected_at' => now(),
            'review_notes' => $notes,
            'verified_by_user_id' => $userId,
        ]);
        $request->proofs()->latest()->first()?->update([
            'status' => PaymentStatus::Rejected,
            'reviewed_at' => now(),
            'review_notes' => $notes,
            'reviewed_by_user_id' => $userId,
        ]);
    }

    private function defaultBank(): ?BankAccount
    {
        return BankAccount::where('is_default', true)->where('is_active', true)->first()
            ?? BankAccount::where('is_active', true)->first();
    }
}
