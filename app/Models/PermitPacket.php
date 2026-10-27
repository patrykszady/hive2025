<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A project's permit packet: the cover letter's words (title, jurisdiction,
 * the codes it lists), the plans and engineer notes uploaded for it, the
 * estimate that is its Scope of Work & Contract, and the merged PDF
 * (App\Support\Permits\PermitPacketBuilder). One per project, regenerated
 * in place.
 */
class PermitPacket extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id', 'belongs_to_vendor_id', 'estimate_id', 'title', 'jurisdiction', 'codes', 'include_contract',
        'plans_path', 'plans_name', 'notes_path', 'notes_name', 'packet_path', 'packet_name',
        'generated_at', 'generated_by_user_id', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'codes' => 'array',
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

    /** The sections the cover letter lists, in packet order. @return list<string> */
    public function contents(): array
    {
        return array_values(array_filter([
            'Cover Letter',
            $this->include_contract && $this->estimate_id ? 'Scope of Work & Contract' : null,
            $this->plans_path ? 'Plans' : null,
            $this->notes_path ? 'Structural Engineer Notes' : null,
        ]));
    }
}
