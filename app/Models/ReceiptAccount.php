<?php

namespace App\Models;

use App\Scopes\ReceiptAccountScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReceiptAccount extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'options' => 'array',
        ];
    }

    protected static function booted()
    {
        static::addGlobalScope(new ReceiptAccountScope);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function belongs_to_vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function distribution(): BelongsTo
    {
        return $this->belongsTo(Distribution::class);
    }

    /**
     * Receipts associated to this receipt account via shared vendor_id.
     */
    public function receipts(): HasMany
    {
        return $this->hasMany(Receipt::class, 'vendor_id', 'vendor_id');
    }
}
