<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Receipt extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'options' => 'array',
        ];
    }

    /**
     * Normalize receipt_type: treat 0, empty string, and other "empty" values as NULL
     */
    protected function receiptType(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => in_array($value, [0, '0', '', null], true) ? null : $value,
            // set: fn ($value) => in_array($value, [0, '0', '', null], true) ? null : $value,
        );
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

}
