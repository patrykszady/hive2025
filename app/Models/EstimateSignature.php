<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EstimateSignature extends Model
{
    protected $fillable = [
        'estimate_id',
        'signer_name',
        'signer_email',
        'signature_data',
        'signature_type',
        'ip_address',
        'user_agent',
        'document_hash',
        'signed_at',
    ];

    protected function casts(): array
    {
        return [
            'signed_at' => 'datetime',
        ];
    }

    public function estimate(): BelongsTo
    {
        return $this->belongsTo(Estimate::class);
    }
}
