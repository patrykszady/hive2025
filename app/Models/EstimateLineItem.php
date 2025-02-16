<?php

namespace App\Models;

use App\Models\Traits\Sortable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\SoftDeletes;

class EstimateLineItem extends Pivot
    // class EstimateLineItem extends Model
{
    use HasFactory, SoftDeletes, Sortable;

    //via_vendor
    // public function via_vendor()
    // {
    //     return $this->belongsTo(Vendor::class, 'via_vendor_id')->withoutGlobalScopes();
    // }
    protected function scopeSortable($query, $estimate_line_item)
    {
        return $estimate_line_item->section->estimate_line_items();
    }

    public function estimate(): BelongsTo
    {
        return $this->belongsTo(Estimate::class)->withTimestamps();
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(EstimateSection::class);
    }
}
