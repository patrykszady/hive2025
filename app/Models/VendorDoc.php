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

    /**
     * EWCCV monitoring state for the vendor-docs card, stamped into
     * options['ewccv'] by ewccv:scrape-tracking. Lives here rather than in the
     * blade because @php blocks inside Blaze component slots miscompile.
     *
     * @return array{tracking: bool, tip: string, verified_at: string|null}|null null when never looked up
     */
    public function getEwccvSummaryAttribute(): ?array
    {
        if (($this->attributes['type'] ?? null) !== 'workers') {
            return null;
        }

        $state = $this->options['ewccv'] ?? null;

        if (! is_array($state) || empty($state['status'])) {
            return null;
        }

        $tip = match ($state['status']) {
            'tracking' => 'EWCCV coverage tracking enabled',
            'failed' => 'EWCCV lookup failed: '.str_replace('_', ' ', (string) ($state['reason'] ?? 'unknown')),
            'skipped' => 'EWCCV lookup skipped: '.str_replace('_', ' ', (string) ($state['reason'] ?? 'unknown')),
            default => 'EWCCV: '.$state['status'],
        };

        $checked = ! empty($state['checked_at'])
            ? \Carbon\Carbon::parse($state['checked_at'])->format('m/d/Y')
            : null;

        if ($checked) {
            $tip .= ' · checked '.$checked;
        }

        return [
            'tracking' => $state['status'] === 'tracking',
            'tip' => $tip,
            // The policy counts as verified only when EWCCV matched it and
            // tracking is on — a failed lookup's checked_at is not verification.
            'verified_at' => $state['status'] === 'tracking' ? $checked : null,
        ];
    }

    /**
     * TrackMyVendor compliance state, pushed in by webhook (that product has
     * no API to poll).
     *
     * @return array{compliant: bool, tip: string, verified_at: string|null}|null
     */
    public function getTrackmyvendorSummaryAttribute(): ?array
    {
        $state = $this->options['trackmyvendor'] ?? null;

        if (! is_array($state) || empty($state['status'])) {
            return null;
        }

        $checked = ! empty($state['checked_at'])
            ? \Carbon\Carbon::parse($state['checked_at'])->format('m/d/Y')
            : null;

        $compliant = (bool) ($state['compliant'] ?? false);

        $tip = $compliant
            ? 'TrackMyVendor: documents compliant'
            : 'TrackMyVendor: NOT compliant';

        // An expiry warning is still compliant today, but worth surfacing.
        if ($compliant && isset($state['expiring_in_days'])) {
            $tip .= ' (expires in '.$state['expiring_in_days'].' days)';
        }

        if ($checked) {
            $tip .= ' · checked '.$checked;
        }

        return [
            'compliant' => $compliant,
            'tip' => $tip,
            'verified_at' => $compliant ? $checked : null,
        ];
    }

    /**
     * What the Verified column shows, across every verification provider.
     *
     * Independent sources answering different questions:
     *   EWCCV          — the carrier's filing with the state rating bureau.
     *   TrackMyVendor  — the certificate we collected, and its expiry.
     *
     * A positive from either is real verification, so the column shows the
     * most recent one; the tooltip always lists everything we know, including
     * a provider that disagrees. A failure is never allowed to mask a success
     * — that is the case where a policy really is covered and one source has
     * simply not caught up.
     *
     * @return array{verified: bool, verified_at: string|null, tip: string, sources: array<int, string>}|null
     */
    public function getVerificationSummaryAttribute(): ?array
    {
        $parts = array_values(array_filter([
            $this->ewccv_summary ? ['ok' => $this->ewccv_summary['tracking'], 'at' => $this->ewccv_summary['verified_at'], 'tip' => $this->ewccv_summary['tip'], 'name' => 'ewccv'] : null,
            $this->trackmyvendor_summary ? ['ok' => $this->trackmyvendor_summary['compliant'], 'at' => $this->trackmyvendor_summary['verified_at'], 'tip' => $this->trackmyvendor_summary['tip'], 'name' => 'trackmyvendor'] : null,
        ]));

        if ($parts === []) {
            return null;
        }

        $verified = collect($parts)->contains(fn ($p) => $p['ok']);

        // Newest confirming date wins — m/d/Y sorts wrong as a string, so
        // compare the underlying dates.
        $verifiedAt = collect($parts)
            ->filter(fn ($p) => $p['ok'] && $p['at'])
            ->sortByDesc(fn ($p) => \Carbon\Carbon::createFromFormat('m/d/Y', $p['at'])->timestamp)
            ->first()['at'] ?? null;

        return [
            'verified' => $verified,
            'verified_at' => $verifiedAt,
            'tip' => collect($parts)->pluck('tip')->implode(' · '),
            'sources' => collect($parts)->pluck('name')->all(),
        ];
    }
}
