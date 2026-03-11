<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class EstimateLineItemAllowance extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'estimate_line_item_id',
        'description',
        'amount',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    public function estimateLineItem(): BelongsTo
    {
        return $this->belongsTo(EstimateLineItem::class, 'estimate_line_item_id');
    }
}
