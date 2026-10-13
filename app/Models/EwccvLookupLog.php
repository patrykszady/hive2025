<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One EWCCV lookup attempt for one vendor: a tracking lookup for the policy
 * on file, or a coverage check as of a date. Keeps what the scraper searched
 * (the name and address variants), the employers EWCCV listed with how each
 * compared, and the outcome, so a lookup that found nothing can be reviewed
 * later and searched by hand with the same facts in front of you.
 */
class EwccvLookupLog extends Model
{
    public const KIND_TRACKING = 'tracking';

    public const KIND_COVERAGE_CHECK = 'coverage_check';

    protected $fillable = [
        'vendor_id', 'vendor_doc_id', 'belongs_to_vendor_id', 'kind', 'coverage_date', 'outcome', 'reason',
        'our_policy_number', 'matched_name', 'matched_policy', 'match_type', 'score', 'searched', 'candidates',
        'session_age_minutes', 'run_at',
    ];

    protected function casts(): array
    {
        return [
            'coverage_date' => 'date',
            'score' => 'float',
            'searched' => 'array',
            'candidates' => 'array',
            'run_at' => 'datetime',
        ];
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class)->withoutGlobalScopes();
    }

    public function vendorDoc(): BelongsTo
    {
        return $this->belongsTo(VendorDoc::class)->withoutGlobalScopes();
    }

    /** Badge colour for the review list. */
    public function tone(): string
    {
        return match ($this->outcome) {
            'tracking', 'covered' => 'green',
            'not_found', 'no_match' => 'red',
            'skipped' => 'zinc',
            default => 'amber',
        };
    }

    /** "tracking", "covered on 12/31/2025", "no match", "failed: http 500"… */
    public function headline(): string
    {
        $what = match ($this->outcome) {
            'tracking' => 'tracking enabled',
            'covered' => 'covered on '.$this->coverage_date?->format('m/d/Y'),
            'not_found' => 'nothing found'.($this->coverage_date ? ' for '.$this->coverage_date->format('m/d/Y') : ''),
            'no_match' => 'results, none matched',
            'skipped' => 'skipped',
            default => 'failed',
        };
        if ($this->reason && ! in_array($this->outcome, ['tracking', 'covered'], true)) {
            $what .= ': '.str_replace('_', ' ', $this->reason);
        }

        return $what;
    }

    /** "KOT CONSTRUCTION INC, policy WC9 (employerName, score 0.50)", or null when nothing matched. */
    public function matchedSummary(): ?string
    {
        if (! $this->matched_name) {
            return null;
        }
        $how = array_filter([$this->match_type, $this->score !== null ? 'score '.number_format((float) $this->score, 2) : null]);

        return $this->matched_name
            .($this->matched_policy ? ', policy '.$this->matched_policy : '')
            .($how !== [] ? ' ('.implode(', ', $how).')' : '');
    }

    /** The searches made, one short string each: employer "Kot" → 2. */
    public function searchLines(): array
    {
        return collect((array) $this->searched)
            ->map(fn ($s) => is_array($s) && filled($s['term'] ?? null)
                ? sprintf('%s "%s" → %s', $s['type'] ?? 'name', $s['term'], filled($s['reason'] ?? null) ? str_replace('_', ' ', (string) $s['reason']) : (($s['count'] ?? 0).' result(s)'))
                : null)
            ->filter()
            ->values()
            ->all();
    }

    /** The employers EWCCV listed, compared: KOT CONSTRUCTION INC · policy X · score 0.50. */
    public function candidateLines(): array
    {
        return collect((array) $this->candidates)
            ->map(fn ($c) => is_array($c) && filled($c['employerName'] ?? null)
                ? sprintf('%s · policy %s · score %s', $c['employerName'], $c['policyNumber'] ?? '—', isset($c['score']) ? number_format((float) $c['score'], 2) : '—')
                : null)
            ->filter()
            ->values()
            ->all();
    }
}
