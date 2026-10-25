<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a sub's document (EstimateSubDocument), kept so a sub's
 * prices and scope can be looked back on: what they listed, their price,
 * and the catalog item it matched (line_item_id, match_source model |
 * alias | manual). applied marks lines that went into a change order.
 */
class EstimateSubDocumentItem extends Model
{
    protected $fillable = [
        'estimate_sub_document_id', 'belongs_to_vendor_id', 'sub_vendor_id', 'project_id', 'index', 'description',
        'quantity', 'unit', 'unit_price', 'amount', 'line_item_id', 'match_source', 'applied', 'document_date',
    ];

    protected function casts(): array
    {
        return ['quantity' => 'float', 'unit_price' => 'float', 'amount' => 'float', 'applied' => 'boolean', 'document_date' => 'date'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(EstimateSubDocument::class, 'estimate_sub_document_id');
    }

    public function lineItem(): BelongsTo
    {
        return $this->belongsTo(LineItem::class);
    }
}
