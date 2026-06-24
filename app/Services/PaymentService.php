<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Models\BankAccount;
use App\Models\PaymentRequest;
use App\Models\Quote;

class PaymentService
{
    /** Create the deposit payment request for an accepted quote. */
    public function createDeposit(Quote $quote): PaymentRequest
    {
        $bank = BankAccount::where('is_default', true)->where('is_active', true)->first()
            ?? BankAccount::where('is_active', true)->first();

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
        $request->lead?->update(['stage' => \App\Enums\LeadStage::Paid]);
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
}
