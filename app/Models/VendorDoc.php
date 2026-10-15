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

        // A coverage check EWCCV answered for a day inside this policy's term
        // (two days before expiration, an audit's end date) verifies the
        // policy when tracking does not: it says EWCCV listed it in force.
        if ((! is_array($state) || ($state['status'] ?? null) !== 'tracking') && ($check = $this->latestCoveredCheck())) {
            $covered = $check->coverage_date->format('m/d/Y');

            return [
                'tracking' => true,
                'tip' => sprintf('EWCCV: covered on %s (%s, policy %s) · checked %s', $covered, $check->employer_name ?: 'listed', $check->policy_number ?: '?', $check->checked_at?->format('m/d/y') ?? '?'),
                'verified_at' => $covered,
                'alert' => false,
            ];
        }

        // A coverage check still waiting for a browser session: say so, so
        // the doc explains why the last attempt is what it still shows.
        $queued = $this->pendingCheckNote();

        if (! is_array($state) || empty($state['status'])) {
            return $queued === '' ? null : ['tracking' => false, 'tip' => 'EWCCV: not looked up yet'.$queued, 'verified_at' => null, 'alert' => false];
        }

        $notice = is_array($state['notice'] ?? null) ? $state['notice'] : null;
        $noticeDate = ! empty($notice['received_at']) ? \Carbon\Carbon::parse($notice['received_at'])->format('m/d/Y') : null;

        $tip = match ($state['status']) {
            'tracking' => (($notice['kind'] ?? null) === 'reinstatement'
                ? 'EWCCV: coverage reinstated'.($noticeDate ? ' on '.$noticeDate : '').', tracking enabled'
                : 'EWCCV coverage tracking enabled').self::ewccvPolicyDifference($state),
            'cancelled' => 'EWCCV: coverage '.(($notice['kind'] ?? '') === 'nonrenewal' ? 'NOT RENEWED' : 'CANCELLED').($noticeDate ? ' as of '.$noticeDate : '').' — get a new certificate',
            'pending' => ($state['reason'] ?? '') === 'not_tracked_by_ewccv'
                ? 'EWCCV no longer lists this policy as tracked; it is re-tracked when EWCCV can be searched'
                : 'Not looked up on EWCCV yet; insurer notices to certificates@ are watched',
            'failed' => 'EWCCV lookup failed: '.str_replace('_', ' ', (string) ($state['reason'] ?? 'unknown')).self::ewccvSearchNotes($state)
                .(in_array($state['reason'] ?? '', EwccvCoverageCheck::NOT_FOUND_REASONS, true) ? $this->previousPolicyNote() : ''),
            'skipped' => 'EWCCV lookup skipped: '.str_replace('_', ' ', (string) ($state['reason'] ?? 'unknown')),
            default => 'EWCCV: '.$state['status'],
        };

        $checkedAt = ! empty($state['checked_at']) ? \Carbon\Carbon::parse($state['checked_at']) : null;
        $checked = $checkedAt?->format('m/d/Y');

        // What the Verified column shows is the day EWCCV confirmed coverage
        // FOR: a tracking confirmation speaks for the day it ran, a coverage
        // check for its own date; the later of the two wins. "Checked" is
        // when we asked, and lives in the tooltip.
        $verifiedFor = $state['status'] === 'tracking' ? $checkedAt?->copy()->startOfDay() : null;
        $coveredCheck = $state['status'] === 'tracking' ? $this->latestCoveredCheck() : null;
        if ($coveredCheck && ($verifiedFor === null || $coveredCheck->coverage_date->gt($verifiedFor))) {
            $verifiedFor = $coveredCheck->coverage_date->copy();
            $tip = 'EWCCV: covered on '.$verifiedFor->format('m/d/Y').' · '.$tip;
        }

        if ($checkedAt) {
            $tip .= ' · checked '.$checkedAt->format('m/d/y');
        }
        $tip .= $queued;

        return [
            'tracking' => $state['status'] === 'tracking',
            'tip' => $tip,
            // The policy counts as verified only when EWCCV matched it and
            // tracking is on — a failed lookup's checked_at is not verification.
            'verified_at' => $state['status'] === 'tracking' ? $verifiedFor?->format('m/d/Y') : null,
            // EWCCV reported the coverage gone: the column goes red, not amber.
            'alert' => $state['status'] === 'cancelled',
        ];
    }

    /** " · EWCCV check as of 10/06/2026 queued", or nothing. */
    protected function pendingCheckNote(): string
    {
        if (! $this->vendor_id || ! $this->effective_date || ! $this->expiration_date) {
            return '';
        }
        $pending = EwccvCoverageCheck::query()
            ->where('vendor_id', $this->vendor_id)
            ->pending()
            ->whereDate('coverage_date', '>=', $this->effective_date)
            ->whereDate('coverage_date', '<=', $this->expiration_date)
            ->orderByDesc('coverage_date')
            ->first();

        return $pending ? ' · EWCCV check as of '.$pending->coverage_date->format('m/d/Y').' queued' : '';
    }

    /**
     * The latest coverage check EWCCV answered "covered" for a day inside
     * this policy's term, if any.
     */
    public function latestCoveredCheck(): ?EwccvCoverageCheck
    {
        if (! $this->vendor_id || ! $this->effective_date || ! $this->expiration_date) {
            return null;
        }

        return EwccvCoverageCheck::query()
            ->where('vendor_id', $this->vendor_id)
            ->where('status', EwccvCoverageCheck::STATUS_COVERED)
            ->whereDate('coverage_date', '>=', $this->effective_date)
            ->whereDate('coverage_date', '<=', $this->expiration_date)
            ->orderByDesc('coverage_date')
            ->first();
    }

    /**
     * The workers comp certificate this one renewed: the vendor's latest
     * policy that ended by the day this one started (a day's slack), within
     * the year before.
     */
    public function previousWorkersDoc(): ?self
    {
        if (! $this->vendor_id || ! $this->effective_date) {
            return null;
        }

        return self::withoutGlobalScopes()
            ->where('type', 'workers')
            ->where('vendor_id', $this->vendor_id)
            ->where('belongs_to_vendor_id', $this->belongs_to_vendor_id)
            ->whereKeyNot($this->getKey())
            ->whereNotNull('effective_date')
            ->whereDate('expiration_date', '<=', $this->effective_date->copy()->addDay())
            ->whereDate('expiration_date', '>=', $this->effective_date->copy()->subYear())
            ->orderByDesc('expiration_date')
            ->first();
    }

    /**
     * EWCCV lists a renewal only once the insurer reports it, often weeks
     * after it starts. Until then the row says how the policy before it
     * stood on its last day (Patryk, 2026-10-14).
     */
    protected function previousPolicyNote(): string
    {
        $previous = $this->previousWorkersDoc();
        if (! $previous) {
            return '';
        }
        if ($check = $previous->latestCoveredCheck()) {
            return sprintf(' · EWCCV may not list this renewal yet; the previous policy (%s) was covered on %s', $previous->number, $check->coverage_date->format('m/d/Y'));
        }

        return $previous->pendingCheckNote() !== ''
            ? sprintf(' · EWCCV may not list this renewal yet; the previous policy (%s) is queued for a check as of its last day', $previous->number)
            : '';
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
        if (($state['match_type'] ?? null) === 'policyNumber' && isset($state['policy_score'])) {
            return sprintf(' · EWCCV lists policy %s, %d%% alike the certificate\'s %s', (string) $state['matched_policy'], (int) round(((float) $state['policy_score']) * 100), (string) $this->attributes['number']);
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
        $checkedShort = ! empty($state['checked_at']) ? \Carbon\Carbon::parse($state['checked_at'])->format('m/d/y') : null;

        $compliant = (bool) ($state['compliant'] ?? false);

        $tip = $compliant
            ? 'TrackMyVendor: documents compliant'
            : 'TrackMyVendor: NOT compliant';

        // An expiry warning is still compliant today, but worth surfacing.
        if ($compliant && isset($state['expiring_in_days'])) {
            $tip .= ' (expires in '.$state['expiring_in_days'].' days)';
        }

        if ($checkedShort) {
            $tip .= ' · checked '.$checkedShort;
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
        $carrier = $this->carrier_notice_summary;
        $parts = array_values(array_filter([
            $carrier ? ['ok' => false, 'at' => null, 'tip' => $carrier['tip'], 'name' => 'carrier', 'alert' => $carrier['alert']] : null,
            $this->ewccv_summary ? ['ok' => $this->ewccv_summary['tracking'], 'at' => $this->ewccv_summary['verified_at'], 'tip' => $this->ewccv_summary['tip'], 'name' => 'ewccv', 'alert' => $this->ewccv_summary['alert']] : null,
            $this->trackmyvendor_summary ? ['ok' => $this->trackmyvendor_summary['compliant'], 'at' => $this->trackmyvendor_summary['verified_at'], 'tip' => $this->trackmyvendor_summary['tip'], 'name' => 'trackmyvendor'] : null,
        ]));

        if ($parts === []) {
            return null;
        }

        $verified = collect($parts)->contains(fn ($p) => $p['ok']);
        // The insurer's own notice outranks an earlier confirmation: the
        // carrier is the one cancelling.
        if ($carrier && $carrier['alert']) {
            $verified = false;
        }

        // Newest confirming date wins — m/d/Y sorts wrong as a string, so
        // compare the underlying dates.
        $verifiedAt = $verified ? (collect($parts)
            ->filter(fn ($p) => $p['ok'] && $p['at'])
            ->sortByDesc(fn ($p) => \Carbon\Carbon::createFromFormat('m/d/Y', $p['at'])->timestamp)
            ->first()['at'] ?? null) : null;

        return [
            'verified' => $verified,
            'verified_at' => $verifiedAt,
            // m/d/y for the card's narrow Verified column.
            'verified_at_short' => $verifiedAt ? \Carbon\Carbon::createFromFormat('m/d/Y', $verifiedAt)->format('m/d/y') : null,
            'tip' => collect($parts)->pluck('tip')->implode(' · '),
            'sources' => collect($parts)->pluck('name')->all(),
            'alert' => (bool) collect($parts)->contains(fn (array $p) => ! empty($p['alert'])),
            // What the red label says: the insurer's words when it sent a notice.
            'alert_label' => $carrier && $carrier['alert'] ? $carrier['label'] : 'cancelled',
        ];
    }

    /**
     * An insurer's notice to the certificate holder, pinned by
     * CarrierNoticeIntake from the certificates mailbox: cancelled, cancelling
     * unless paid, not renewed, or reinstated.
     *
     * @return array{alert: bool, label: string, tip: string}|null
     */
    public function getCarrierNoticeSummaryAttribute(): ?array
    {
        $notice = $this->options['carrier_notice'] ?? null;
        if (! is_array($notice) || empty($notice['kind'])) {
            return null;
        }
        $effective = ! empty($notice['effective_date']) ? \Carbon\Carbon::parse($notice['effective_date'])->format('m/d/Y') : null;
        $received = ! empty($notice['received_at']) ? \Carbon\Carbon::parse($notice['received_at'])->format('m/d/Y') : null;

        [$label, $what] = match ($notice['kind']) {
            \App\Support\Insurance\CarrierNotice::CANCELLATION => ['cancelled', 'CANCELLED'.($effective ? ' effective '.$effective : '')],
            \App\Support\Insurance\CarrierNotice::PENDING_CANCELLATION => ['cancelling', 'cancels'.($effective ? ' on '.$effective : '').' unless the premium is paid'],
            \App\Support\Insurance\CarrierNotice::NONRENEWAL => ['not renewed', 'NOT RENEWED'.($effective ? ' as of '.$effective : '')],
            \App\Support\Insurance\CarrierNotice::REINSTATEMENT => ['reinstated', 'reinstated'.($effective ? ' as of '.$effective : '')],
            default => [(string) $notice['kind'], (string) $notice['kind']],
        };

        return [
            'alert' => in_array($notice['kind'], \App\Support\Insurance\CarrierNotice::ALERT_KINDS, true),
            'label' => $label,
            'tip' => 'Insurer notice: '.$what.($received ? ' · received '.$received : '').(! empty($notice['from']) ? ' from '.$notice['from'] : ''),
        ];
    }
}
