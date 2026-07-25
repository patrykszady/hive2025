<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A generated GCSS ("Sworn Statement of Contractor to Owner") for one draw.
 * The rendered PDF is stored on the 'files' disk; the record lists alongside
 * lien waivers on the project card.
 */
class SwornStatement extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'project_id', 'belongs_to_vendor_id', 'this_payment',
        'status', 'sent_at', 'signed_at', 'path', 'signed_path', 'filename', 'created_by_user_id',
    ];

    protected $casts = [
        'this_payment' => 'decimal:2',
        // Same lifecycle as waivers: Draft → Sent (package emailed) → Signed
        // (notarized scan returned via the waivers@ barcode ingest).
        'status' => \App\Enums\LienWaiverStatus::class,
        'sent_at' => 'datetime',
        'signed_at' => 'datetime',
    ];

    /**
     * 1-based position of this statement among the project's draws (Draw 1 =
     * oldest). Matches the numbering shown on the project card.
     */
    public function drawNumber(): int
    {
        return static::query()
            ->where('project_id', $this->project_id)
            ->where('belongs_to_vendor_id', $this->belongs_to_vendor_id)
            ->where('id', '<=', $this->id)
            ->count();
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'belongs_to_vendor_id');
    }
}
