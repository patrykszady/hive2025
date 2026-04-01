<?php

namespace App\Models;

use App\Traits\Sortable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class EstimateLineItem extends Pivot
{
    use HasFactory, LogsActivity, SoftDeletes, Sortable;

    protected $primaryKey = 'id';

    public $incrementing = true;

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

    public function line_item(): BelongsTo
    {
        return $this->belongsTo(LineItem::class);
    }

    public function allowances(): HasMany
    {
        return $this->hasMany(EstimateLineItemAllowance::class, 'estimate_line_item_id', 'id');
    }

    /**
     * Override displace to record the original order before it's wiped to 999999.
     */
    public function displace(): void
    {
        activity('estimates')
            ->performedOn($this)
            ->causedBy(auth()->user())
            ->event('deleted')
            ->withProperties(['old' => ['order' => $this->order]])
            ->log('deleted');

        $this->move(999999);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'name',
                'category',
                'sub_category',
                'unit_type',
                'quantity',
                'cost',
                'total',
                'desc',
                'notes',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('estimates');
    }
}
