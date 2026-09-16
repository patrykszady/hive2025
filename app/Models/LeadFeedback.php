<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadFeedback extends Model
{
    use HasFactory;

    protected $table = 'lead_feedback';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'meta' => 'array',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}
