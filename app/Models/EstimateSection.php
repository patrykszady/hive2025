<?php

namespace App\Models;

use App\Traits\Sortable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class EstimateSection extends Model
{
    use HasFactory, LogsActivity, SoftDeletes, Sortable;

    protected $fillable = ['estimate_id', 'order', 'name', 'total', 'bid_id', 'created_at', 'updated_at', 'deleted_at'];

    protected function scopeSortable($query, $section)
    {
        return $section->estimate->estimate_sections();
    }

    public function estimate(): BelongsTo
    {
        return $this->belongsTo(Estimate::class);
    }

    public function estimate_line_items(): HasMany
    {
        return $this->hasMany(EstimateLineItem::class, 'section_id');
    }

    public function bid(): BelongsTo
    {
        return $this->belongsTo(Bid::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'total'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('estimates');
    }
}
