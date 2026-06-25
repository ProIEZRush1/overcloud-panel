<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PaymentRequest extends Model
{
    protected $fillable = [
        'lead_id', 'quote_id', 'project_id', 'subscription_id', 'bank_account_id',
        'verified_by_user_id', 'type', 'amount_cents', 'currency', 'status',
        'bank_details_snapshot', 'reference', 'due_date',
        'sent_at', 'reminded_at', 'verified_at', 'rejected_at', 'review_notes',
    ];

    protected $casts = [
        'type' => PaymentType::class,
        'status' => PaymentStatus::class,
        'amount_cents' => 'integer',
        'bank_details_snapshot' => 'array',
        'due_date' => 'date',
        'sent_at' => 'datetime',
        'reminded_at' => 'datetime',
        'verified_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }

    public function proofs(): HasMany
    {
        return $this->hasMany(PaymentProof::class);
    }

    public function latestProof(): HasOne
    {
        return $this->hasOne(PaymentProof::class)->latestOfMany();
    }

    public function isVerified(): bool
    {
        return $this->status === PaymentStatus::Verified;
    }
}
