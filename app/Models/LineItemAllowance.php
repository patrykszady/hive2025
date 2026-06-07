<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class LineItemAllowance extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'line_item_id',
        'description',
        'pricing_mode',
        'unit_amount',
        'amount',
        'belongs_to_vendor_id',
    ];

    protected function casts(): array
    {
        return [
            'unit_amount' => 'decimal:2',
            'amount' => 'decimal:2',
        ];
    }

    public function lineItem(): BelongsTo
    {
        return $this->belongsTo(LineItem::class);
    }

    public function estimateLineItemAllowances(): HasMany
    {
        return $this->hasMany(EstimateLineItemAllowance::class, 'line_item_allowance_id');
    }
}
