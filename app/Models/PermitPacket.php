<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A project's permit packet: the cover letter's words (title, jurisdiction,
 * the codes it lists), the plan PDFs (any number, merged in upload order)
 * and the engineer notes uploaded for it, the
 * estimate that is its Scope of Work & Contract, and the merged PDF
 * (App\Support\Permits\PermitPacketBuilder). One per project, regenerated
 * in place.
 */
class PermitPacket extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id', 'belongs_to_vendor_id', 'estimate_id', 'title', 'jurisdiction', 'codes', 'include_contract',
        'plans', 'notes_path', 'notes_name', 'packet_path', 'packet_name',
        'generated_at', 'generated_by_user_id', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'codes' => 'array',
            'plans' => 'array',
            'include_contract' => 'boolean',
            'generated_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withoutGlobalScopes();
    }

    public function estimate(): BelongsTo
    {
        return $this->belongsTo(Estimate::class)->withoutGlobalScopes();
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by_user_id');
    }

    /**
     * The plan PDFs, in packet order.
     *
     * @return list<array{path: string, name: string}>
     */
    public function planFiles(): array
    {
        return array_values(array_filter(
            (array) $this->plans,
            fn ($plan): bool => is_array($plan) && ! empty($plan['path']),
        ));
    }

    public function hasPlans(): bool
    {
        return $this->planFiles() !== [];
    }

    /** The sections the cover letter lists, in packet order. @return list<string> */
    public function contents(): array
    {
        return array_values(array_filter([
            'Cover Letter',
            $this->include_contract && $this->estimate_id ? 'Scope of Work & Contract' : null,
            $this->hasPlans() ? 'Plans' : null,
            $this->notes_path ? 'Structural Engineer Notes' : null,
        ]));
    }
}
