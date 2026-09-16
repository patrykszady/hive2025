<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The embedding of a past section's text, so a new enquiry can be matched to
 * the closest past jobs by meaning rather than by room words.
 */
class EstimateSectionEmbedding extends Model
{
    protected $guarded = [];

    protected $casts = [
        'vector' => 'array',
    ];

    public function section(): BelongsTo
    {
        return $this->belongsTo(EstimateSection::class, 'section_id')->withoutGlobalScopes();
    }
}
