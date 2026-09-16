<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One run of the AI estimate generator: what was asked, what Claude drafted
 * and, once the estimator has been through it, what changed.
 */
class EstimateAiDraft extends Model
{
    public const DRAFTED = 'drafted';

    public const FINISHED = 'finished';

    public const DISCARDED = 'discarded';

    protected $guarded = [];

    protected $casts = [
        'floorplan' => 'array',
        'drafted_items' => 'array',
        'usage' => 'array',
        'final_items' => 'array',
        'corrections' => 'array',
        'finalized_at' => 'datetime',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class)->withoutGlobalScopes();
    }

    public function estimate(): BelongsTo
    {
        return $this->belongsTo(Estimate::class)->withoutGlobalScopes();
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(EstimateSection::class, 'section_id')->withoutGlobalScopes();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
