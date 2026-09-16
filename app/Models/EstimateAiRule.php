<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A line in a company's "how we estimate" note. Active rules are read by the
 * AI generator on every draft; proposed ones wait for a person to approve.
 */
class EstimateAiRule extends Model
{
    public const ACTIVE = 'active';

    public const PROPOSED = 'proposed';

    public const DISMISSED = 'dismissed';

    public const MANUAL = 'manual';

    public const FROM_PATTERN = 'proposed';

    protected $guarded = [];

    protected $casts = [
        'evidence' => 'array',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class)->withoutGlobalScopes();
    }

    public function scopeForVendor(Builder $query, int $vendorId): Builder
    {
        return $query->where('vendor_id', $vendorId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::ACTIVE);
    }

    public function scopeProposed(Builder $query): Builder
    {
        return $query->where('status', self::PROPOSED);
    }
}
