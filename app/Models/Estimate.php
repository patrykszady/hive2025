<?php

namespace App\Models;

use App\Scopes\EstimateScope;
use Carbon\Carbon;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Estimate extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['project_id', 'options', 'belongs_to_vendor_id', 'signed_contract_path', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return [
            'options' => 'array',
        ];
    }

    protected static function booted()
    {
        static::addGlobalScope(new EstimateScope);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function line_items(): BelongsToMany
    {
        return $this->belongsToMany(LineItem::class)->withPivot('id', 'name', 'category', 'sub_category', 'unit_type', 'cost', 'desc', 'notes', 'quantity', 'total', 'section_id')->withTimestamps();
    }

    public function estimate_line_items(): HasMany
    {
        return $this->hasMany(EstimateLineItem::class);
    }

    public function estimate_sections(): HasMany
    {
        return $this->hasMany(EstimateSection::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'belongs_to_vendor_id');
    }

    public function signature(): HasOne
    {
        return $this->hasOne(EstimateSignature::class)->latest('signed_at');
    }

    /**
     * All signatures on this estimate.
     */
    public function signatures(): HasMany
    {
        return $this->hasMany(EstimateSignature::class);
    }

    /**
     * Get the explicitly configured vendor signer IDs for this estimate.
     * Returns an empty collection when no specific signers are configured.
     *
     * @return \Illuminate\Support\Collection<int, int>
     */
    public function getRequiredVendorSignerIdsAttribute(): \Illuminate\Support\Collection
    {
        $ids = $this->options['required_vendor_signer_ids'] ?? [];

        return collect($ids)->map(fn ($id) => (int) $id);
    }

    /**
     * Check if the vendor has signed this estimate.
     *
     * When specific signers are configured, ALL of them must have signed.
     * When none are configured, any single vendor user signature suffices.
     */
    public function isVendorSigned(): bool
    {
        $requiredIds = $this->required_vendor_signer_ids;

        if ($requiredIds->isNotEmpty()) {
            $signedVendorIds = $this->signatures()
                ->whereIn('user_id', $requiredIds)
                ->pluck('user_id');

            return $requiredIds->diff($signedVendorIds)->isEmpty();
        }

        // Fallback: any vendor user signature counts
        $allVendorUserIds = $this->vendor?->users()?->pluck('users.id') ?? collect();

        return $this->signatures()->whereIn('user_id', $allVendorUserIds)->exists();
    }

    /**
     * Check if the estimate has been fully signed (vendor signers + all client users).
     */
    public function isFullySigned(): bool
    {
        if (! $this->isVendorSigned()) {
            return false;
        }

        $requiredClientSigners = $this->project?->client?->users?->count() ?? 1;
        $clientUserIds = $this->project?->client?->users?->pluck('id') ?? collect();
        $clientSignatures = $this->signatures()->whereIn('user_id', $clientUserIds)->count();

        return $clientSignatures >= $requiredClientSigners;
    }

    public function isSigned(): bool
    {
        return $this->signatures()->exists();
    }

    // Define the 'status' accessor
    protected function status(): Attribute
    {
        return Attribute::make(
            get: function () {
                if (is_null($this->deleted_at)) {
                    return 'Active';
                }
                
                // Check if it's force deleted (will be checked in queries with onlyTrashed())
                // For soft deleted items, we consider them "Disabled"
                return 'Disabled';
            }
        );
    }

    public function getClientAttribute()
    {
        return $this->project?->client;
    }

    public function getStartDateAttribute()
    {
        if (isset($this->options['start_date'])) {
            return Carbon::parse($this->options['start_date']);
        } else {
            return null;
        }
    }

    public function getEndDateAttribute()
    {
        if (isset($this->options['end_date'])) {
            return Carbon::parse($this->options['end_date']);
        } else {
            return null;
        }
    }

    public function getReimbursmentsAttribute()
    {
        // Reimbursements are always included
        $projectFinances = $this->project?->finances;

        return data_get($projectFinances, 'reimbursments');
    }

    public function getPaymentsAttribute()
    {
        if (isset($this->options['payments'])) {
            return $this->options['payments'];
        } else {
            return null;
        }
    }

    public function getNumberAttribute()
    {
        $segments = array_filter([
            $this->belongs_to_vendor_id,
            $this->client?->id,
            $this->project?->id,
            $this->id,
        ]);

        return implode('-', $segments);
    }
}
