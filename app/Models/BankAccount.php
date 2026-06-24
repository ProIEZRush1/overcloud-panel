<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BankAccount extends Model
{
    protected $fillable = [
        'label', 'bank', 'beneficiary', 'account_number', 'clabe',
        'currency', 'instructions', 'is_active', 'is_default', 'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_default' => 'boolean',
        'sort_order' => 'integer',
    ];

    /** Snapshot stored on a payment request and rendered to the client. */
    public function toSnapshot(): array
    {
        return [
            'label' => $this->label,
            'bank' => $this->bank,
            'beneficiary' => $this->beneficiary,
            'account_number' => $this->account_number,
            'clabe' => $this->clabe,
            'currency' => $this->currency,
            'instructions' => $this->instructions,
        ];
    }
}
