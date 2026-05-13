<?php

namespace App\Models;

use App\Scopes\VendorDocScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class VendorDoc extends Model
{
    use HasFactory;

    /**
     * Friendly labels for state-issued trade license type codes stored
     * in the `type` column (e.g. Illinois IDPH 055/058).
     */
    public const LICENSE_LABELS = [
        '055' => 'Plumbing Contractor Registration',
        '058' => 'Plumber License',
    ];

    public const DOC_TYPE_LABELS = [
        'general' => 'Liability',
        'professional' => 'Liability',
        'workers' => 'Workers',
        'state_license' => 'State License',
    ];

    protected $fillable = ['type', 'vendor_id', 'effective_date', 'expiration_date', 'number', 'belongs_to_vendor_id', 'doc_filename', 'options', 'created_at', 'updated_at'];
    // protected $dates = ['effective_date', 'expiration_date'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'effective_date' => 'date:Y-m-d',
            'expiration_date' => 'date:Y-m-d',
            'options' => 'array',
        ];
    }

    protected static function booted()
    {
        static::addGlobalScope(new VendorDocScope);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function getTypeAttribute($value)
    {
        return $value;
    }

    /**
     * Human-readable label for the doc type. Maps state-license codes
     * (e.g. "055" => "Plumbing Contractor Registration") and otherwise
     * falls back to the existing ucfirst behaviour.
     */
    public function getTypeLabelAttribute(): string
    {
        $raw = (string) ($this->attributes['type'] ?? '');

        if ($raw === '') {
            return '';
        }

        return self::LICENSE_LABELS[$raw]
            ?? self::DOC_TYPE_LABELS[$raw]
            ?? Str::of($raw)
                ->replace(['_', '-'], ' ')
                ->replaceMatches('/\s+/', ' ')
                ->trim()
                ->title()
                ->toString();
    }
}
