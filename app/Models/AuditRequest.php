<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An audit report someone asked for (the Audit Report form): its period,
 * type and bank accounts, and where it stands. A workers comp audit is
 * checked against EWCCV as of its end date minus two days for every paid
 * sub with a policy active today; until those answers are in, nothing is
 * downloadable, and when they are, the package (certificates PDF + Excel)
 * is emailed to whoever asked (SendAuditWhenReady).
 */
class AuditRequest extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_READY = 'ready';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    /** The "checked" date: this many days before the audit's end date. */
    public const COVERAGE_DAYS_BEFORE_END = 2;

    protected $fillable = [
        'belongs_to_vendor_id', 'requested_by_user_id', 'signature', 'start_date', 'end_date', 'audit_type',
        'bank_account_ids', 'coverage_date', 'status', 'vendors_checked', 'checks', 'pdf_path', 'xlsx_path', 'ready_at', 'emailed_at', 'emailed_to', 'error',
    ];

    /** Attach the package to the email only when it fits comfortably under the mail provider's 10 MB. */
    public const ATTACH_MAX_BYTES = 7 * 1024 * 1024;

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'coverage_date' => 'date',
            'bank_account_ids' => 'array',
            'vendors_checked' => 'array',
            'checks' => 'array',
            'ready_at' => 'datetime',
            'emailed_at' => 'datetime',
        ];
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    /** @param  list<int>  $bankAccountIds */
    public static function signatureFor(string $start, string $end, ?string $type, array $bankAccountIds): string
    {
        $ids = array_map('intval', $bankAccountIds);
        sort($ids);

        return sha1(implode('|', [Carbon::parse($start)->toDateString(), Carbon::parse($end)->toDateString(), (string) $type, implode(',', $ids)]));
    }

    /**
     * The EWCCV coverage date an audit is checked against: two days before
     * the end date, or today when the period has not ended yet — coverage
     * can only be confirmed up to today.
     */
    public static function coverageDateFor(string $end): Carbon
    {
        $asOf = Carbon::parse($end)->subDays(self::COVERAGE_DAYS_BEFORE_END)->startOfDay();

        return $asOf->gt(Carbon::today()) ? Carbon::today() : $asOf;
    }

    /** Whether the checked date had to be pulled back to today. */
    public static function coverageDateIsClamped(string $end): bool
    {
        return Carbon::parse($end)->subDays(self::COVERAGE_DAYS_BEFORE_END)->startOfDay()->gt(Carbon::today());
    }

    /**
     * The request behind an audit page or form submission: found by its
     * parameters, or created — and on creation a workers comp audit queues
     * its EWCCV checks as of the coverage date for every sub that needs one.
     *
     * @param  list<int>  $bankAccountIds
     */
    public static function findOrStart(int $companyVendorId, ?User $requestedBy, string $start, string $end, ?string $type, array $bankAccountIds): self
    {
        $signature = self::signatureFor($start, $end, $type, $bankAccountIds);
        $existing = self::query()->where('belongs_to_vendor_id', $companyVendorId)->where('signature', $signature)->first();
        if ($existing) {
            return $existing;
        }

        $coverageDate = $type === 'workers' ? self::coverageDateFor($end) : null;
        $report = new \App\Services\Audits\AuditReport($companyVendorId, Carbon::parse($start)->toDateString(), Carbon::parse($end)->toDateString(), $type, array_map('intval', $bankAccountIds), $coverageDate);
        $vendorIds = $report->vendorsNeedingCoverage();
        $checks = $report->checksNeeded();
        foreach ($checks as $check) {
            EwccvCoverageCheck::request($check['vendor_id'], $companyVendorId, $check['coverage_date']);
        }

        $audit = self::create([
            'belongs_to_vendor_id' => $companyVendorId,
            'requested_by_user_id' => $requestedBy?->id,
            'signature' => $signature,
            'start_date' => Carbon::parse($start)->toDateString(),
            'end_date' => Carbon::parse($end)->toDateString(),
            'audit_type' => $type,
            'bank_account_ids' => array_values(array_map('intval', $bankAccountIds)),
            'coverage_date' => $coverageDate?->toDateString(),
            'status' => self::STATUS_PENDING,
            'vendors_checked' => $vendorIds,
            'checks' => $checks,
        ]);

        // Nothing to wait for, or a credential at hand: move it along now.
        if ($audit->pendingChecks() === 0) {
            \App\Jobs\SendAuditWhenReady::dispatch();
        } elseif (\App\Support\Ewccv\BridgedSession::canSearch()) {
            \App\Jobs\RunPendingEwccvLookups::dispatch();
        }

        return $audit;
    }

    public function isDownloadable(): bool
    {
        return in_array($this->status, [self::STATUS_READY, self::STATUS_SENT], true);
    }

    /**
     * Ask again for every check this audit needs (per policy, each for its
     * own day): new policies, a changed rule, a failed attempt put back.
     */
    public function queueChecks(): int
    {
        if ($this->audit_type !== 'workers') {
            return 0;
        }
        $report = new \App\Services\Audits\AuditReport($this->belongs_to_vendor_id, $this->start_date->toDateString(), $this->end_date->toDateString(), $this->audit_type, (array) $this->bank_account_ids, $this->coverage_date);
        $checks = $report->checksNeeded();
        $queued = 0;
        foreach ($checks as $check) {
            if (EwccvCoverageCheck::request($check['vendor_id'], $this->belongs_to_vendor_id, $check['coverage_date'])) {
                $queued++;
            }
        }
        $this->forceFill(['vendors_checked' => $report->vendorsNeedingCoverage(), 'checks' => $checks])->save();

        return $queued;
    }

    /** EWCCV answers still outstanding for this audit. */
    public function pendingChecks(): int
    {
        if ($this->audit_type !== 'workers') {
            return 0;
        }
        $pairs = collect((array) ($this->checks ?? []))
            ->filter(fn ($c) => is_array($c) && isset($c['vendor_id'], $c['coverage_date']));
        if ($pairs->isEmpty()) {
            if (empty($this->vendors_checked)) {
                return 0;
            }
            $pairs = collect($this->vendors_checked)->map(fn ($v) => ['vendor_id' => (int) $v, 'coverage_date' => $this->coverage_date?->toDateString()]);
        }

        $pending = EwccvCoverageCheck::query()
            ->where('belongs_to_vendor_id', $this->belongs_to_vendor_id)
            ->whereIn('vendor_id', $pairs->pluck('vendor_id')->unique()->all())
            ->pending()
            ->get(['vendor_id', 'coverage_date']);

        return $pairs->filter(fn ($p) => $pending->contains(fn ($row) => (int) $row->vendor_id === (int) $p['vendor_id'] && $row->coverage_date->toDateString() === $p['coverage_date']))->count();
    }

    public function label(): string
    {
        return sprintf('%s audit %s – %s', ucfirst((string) ($this->audit_type ?: 'general')), $this->start_date->format('m/d/Y'), $this->end_date->format('m/d/Y'));
    }
}
