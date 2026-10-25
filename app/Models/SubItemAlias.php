<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A learned match: a sub's wording for a line → our catalog item. See
 * App\Services\Estimates\SubItemMemory.
 */
class SubItemAlias extends Model
{
    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_CONFIRMED = 'confirmed';

    protected $fillable = [
        'belongs_to_vendor_id', 'sub_vendor_id', 'description_key', 'description', 'line_item_id', 'source',
        'times_used', 'created_by_user_id', 'last_used_at',
    ];

    protected function casts(): array
    {
        return ['times_used' => 'integer', 'last_used_at' => 'datetime'];
    }

    public function lineItem(): BelongsTo
    {
        return $this->belongsTo(LineItem::class);
    }
}
