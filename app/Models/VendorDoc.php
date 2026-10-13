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

        $notice = is_array($state['notice'] ?? null) ? $state['notice'] : null;
        $noticeDate = ! empty($notice['received_at']) ? \Carbon\Carbon::parse($notice['received_at'])->format('m/d/Y') : null;

        $tip = match ($state['status']) {
            'tracking' => (($notice['kind'] ?? null) === 'reinstatement'
                ? 'EWCCV: coverage reinstated'.($noticeDate ? ' on '.$noticeDate : '').', tracking enabled'
                : 'EWCCV coverage tracking enabled').self::ewccvPolicyDifference($state),
            'cancelled' => 'EWCCV: coverage '.(($notice['kind'] ?? '') === 'nonrenewal' ? 'NOT RENEWED' : 'CANCELLED').($noticeDate ? ' as of '.$noticeDate : '').' — get a new certificate',
            'pending' => ($state['reason'] ?? '') === 'not_tracked_by_ewccv'
                ? 'EWCCV no longer lists this policy as tracked; it is re-tracked with the next browser session'
                : 'EWCCV lookup waiting for a browser session: open ewccv.com with the Hive extension and it runs',
            'failed' => 'EWCCV lookup failed: '.str_replace('_', ' ', (string) ($state['reason'] ?? 'unknown')).self::ewccvSearchNotes($state),
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
            // EWCCV reported the coverage gone: the column goes red, not amber.
            'alert' => $state['status'] === 'cancelled',
        ];
    }

    /**
     * When EWCCV matched the employer by name and lists a policy number other
     * than the certificate on file (a renewal, or a wrong match), say so.
     *
     * @param  array<string, mixed>  $state
     */
    protected function ewccvPolicyDifference(array $state): string
    {
        $theirs = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) ($state['matched_policy'] ?? '')));
        $ours = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) ($this->attributes['number'] ?? '')));
        if ($theirs === '' || $ours === '' || $theirs === $ours || str_contains($theirs, $ours) || str_contains($ours, $theirs)) {
            return '';
        }

        return sprintf(' · matched by name (%s); EWCCV lists policy %s, the certificate on file says %s — check it is the same business', (string) ($state['matched_name'] ?? '?'), (string) $state['matched_policy'], (string) $this->attributes['number']);
    }

    /**
     * What the scraper tried and what it found, for a failed lookup's tip:
     * the name and address variants it searched and the closest employers
     * EWCCV listed, so a person can see why nothing matched and look by hand.
     *
     * @param  array<string, mixed>  $state
     */
    public static function ewccvSearchNotes(array $state): string
    {
        $notes = [];
        $searched = collect((array) ($state['searched'] ?? []))
            ->map(fn ($s) => is_array($s) && filled($s['term'] ?? null) ? ($s['type'] ?? 'name').' "'.$s['term'].'"' : null)
            ->filter()
            ->unique()
            ->take(4);
        if ($searched->isNotEmpty()) {
            $notes[] = 'searched '.$searched->implode(', ');
        }
        $candidates = collect((array) ($state['candidates'] ?? []))
            ->map(fn ($c) => is_array($c) && filled($c['employerName'] ?? null)
                ? $c['employerName'].(filled($c['policyNumber'] ?? null) ? ' (policy '.$c['policyNumber'].')' : '')
                : null)
            ->filter()
            ->take(3);
        if ($candidates->isNotEmpty()) {
            $notes[] = 'closest '.$candidates->implode('; ');
        }

        return $notes === [] ? '' : ' — '.implode(' · ', $notes);
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
            $this->ewccv_summary ? ['ok' => $this->ewccv_summary['tracking'], 'at' => $this->ewccv_summary['verified_at'], 'tip' => $this->ewccv_summary['tip'], 'name' => 'ewccv', 'alert' => $this->ewccv_summary['alert']] : null,
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
            'alert' => (bool) collect($parts)->contains(fn (array $p) => ! empty($p['alert'])),
        ];
    }
}
