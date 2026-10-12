<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Whether EWCCV listed a workers comp policy for a vendor on a given date.
 *
 * Tracking (VendorDoc options.ewccv) answers "is the policy on file still in
 * force today?"; this answers the auditor's question instead: was the sub
 * covered on the audit's end date? The audit page requests one check per
 * paid sub; it stays `pending` until a browser session lets the scraper
 * search EWCCV with that coverage date (ewccv:scrape-tracking --verify-only),
 * which records `covered` or `not_found`, or leaves it pending with the
 * reason when the run itself failed.
 */
class EwccvCoverageCheck extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_COVERED = 'covered';

    public const STATUS_NOT_FOUND = 'not_found';

    /** Scraper reasons that are an answer ("EWCCV has nothing"), not a hiccup. */
    public const NOT_FOUND_REASONS = ['no_results', 'no_match', 'no_matching_result', 'missing_address', 'no_items_in_response'];

    protected $fillable = [
        'vendor_id', 'belongs_to_vendor_id', 'coverage_date', 'status', 'policy_number', 'employer_name',
        'match_type', 'reason', 'details', 'searched', 'candidates', 'requested_at', 'checked_at',
    ];

    protected function casts(): array
    {
        return [
            'coverage_date' => 'date',
            'details' => 'array',
            'searched' => 'array',
            'candidates' => 'array',
            'requested_at' => 'datetime',
            'checked_at' => 'datetime',
        ];
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class)->withoutGlobalScopes();
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Ask for a check; true when it is newly waiting (created, or a failed
     * one put back), false when an answer or a pending request already exists.
     */
    public static function request(int $vendorId, int $belongsToVendorId, string $coverageDate): bool
    {
        $check = self::firstOrNew([
            'vendor_id' => $vendorId,
            'belongs_to_vendor_id' => $belongsToVendorId,
            // A Carbon at midnight on both the lookup and the insert: SQLite stores
            // the cast date with a time, and a bare Y-m-d would never match it.
            'coverage_date' => Carbon::parse($coverageDate)->startOfDay(),
        ]);

        if ($check->exists && $check->status !== self::STATUS_PENDING) {
            return false;
        }
        if ($check->exists && $check->status === self::STATUS_PENDING && ! $check->reason) {
            return false;
        }

        $check->fill(['status' => self::STATUS_PENDING, 'reason' => null, 'requested_at' => now()])->save();

        return true;
    }

    /**
     * Pending checks for a tenant, vendor ids by coverage date (Y-m-d): one
     * scraper run per date, since the date goes on every search.
     *
     * @return array<string, list<int>>
     */
    public static function pendingByDate(int $belongsToVendorId): array
    {
        return self::query()
            ->where('belongs_to_vendor_id', $belongsToVendorId)
            ->pending()
            ->get(['vendor_id', 'coverage_date'])
            ->groupBy(fn (self $check) => $check->coverage_date->toDateString())
            ->map(fn ($checks) => $checks->pluck('vendor_id')->map(fn ($id) => (int) $id)->unique()->values()->all())
            ->sortKeys()
            ->all();
    }

    public function isSettled(): bool
    {
        return in_array($this->status, [self::STATUS_COVERED, self::STATUS_NOT_FOUND], true);
    }

    /** Badge colour for the audit page. */
    public function tone(): string
    {
        return match ($this->status) {
            self::STATUS_COVERED => 'green',
            self::STATUS_NOT_FOUND => 'red',
            default => 'amber',
        };
    }

    /** Short badge text. */
    public function badge(): string
    {
        return match ($this->status) {
            self::STATUS_COVERED => 'EWCCV: covered',
            self::STATUS_NOT_FOUND => 'EWCCV: not found',
            default => 'EWCCV: waiting',
        };
    }

    /** The sentence behind the badge. */
    public function label(): string
    {
        $date = $this->coverage_date->format('m/d/Y');
        $checked = $this->checked_at ? ' · checked '.$this->checked_at->format('m/d/Y') : '';

        return match ($this->status) {
            self::STATUS_COVERED => sprintf('Covered on %s: %s%s%s', $date, $this->employer_name ?: 'listed by EWCCV', $this->policy_number ? ', policy '.$this->policy_number : '', $checked),
            self::STATUS_NOT_FOUND => sprintf('Not found on EWCCV for %s (%s)%s', $date, str_replace('_', ' ', (string) ($this->reason ?: 'no results')), $checked),
            default => $this->reason
                ? sprintf('Waiting for a browser session to retry (last attempt: %s)', str_replace('_', ' ', $this->reason))
                : 'Waiting for a browser session: open ewccv.com with the Hive extension and the check runs',
        };
    }
}
