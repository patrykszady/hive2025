<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A scanned check image cropped out of a bank-statement PDF, plus the Azure
 * Content Understanding extraction and its resolved link to a Check (or a
 * Transaction when no Check record exists yet).
 *
 * No global vendor scope: rows are ingested from console pipelines where
 * auth() is guest; consumers must filter on belongs_to_vendor_id explicitly.
 */
class CheckImage extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_MATCHED_CHECK = 'matched_check';

    /** Check number matched a unique check but the amount did not (or was null) — review. */
    public const STATUS_MATCHED_CHECK_NUMBER_ONLY = 'matched_check_number_only';

    /** No check record exists; linked to the cleared bank transaction instead. */
    public const STATUS_MATCHED_TRANSACTION = 'matched_transaction';

    /** Multiple candidates survived — never guess; candidate ids in match_details. */
    public const STATUS_AMBIGUOUS = 'ambiguous';

    public const STATUS_UNMATCHED = 'unmatched';

    /** Linked by a human — excluded from re-matching. */
    public const STATUS_MANUAL = 'manual';

    protected $fillable = [
        'image_filename',
        'statement_filename',
        'bank_account_id',
        'check_id',
        'transaction_id',
        'belongs_to_vendor_id',
        'check_number',
        'amount',
        'check_date',
        'account_number',
        'payee',
        'payee_user_id',
        'payee_vendor_id',
        'check_fields',
        'analyzer_id',
        'analyzed_at',
        'match_status',
        'match_details',
        'matched_at',
    ];

    protected function casts(): array
    {
        return [
            'check_fields'  => 'array',
            'match_details' => 'array',
            'check_date'    => 'date:Y-m-d',
            'analyzed_at'   => 'datetime',
            'matched_at'    => 'datetime',
            'amount'        => 'decimal:2',
        ];
    }

    public function bank_account(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class)->withoutGlobalScopes();
    }

    public function check(): BelongsTo
    {
        return $this->belongsTo(Check::class)->withoutGlobalScopes();
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class)->withoutGlobalScopes();
    }

    public function payeeUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payee_user_id');
    }

    public function payeeVendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'payee_vendor_id')->withoutGlobalScopes();
    }

    /**
     * Best displayable payee: resolved entity name, falling back to the
     * handwritten text from the analyzer.
     */
    public function getResolvedPayeeNameAttribute(): ?string
    {
        return $this->payeeUser?->full_name
            ?? $this->payeeVendor?->business_name
            ?? $this->payee;
    }

    /**
     * Relative path on the 'files' disk.
     */
    public function getFilePathAttribute(): string
    {
        return 'checks/' . $this->image_filename;
    }

    public function getFileUrlAttribute(): string
    {
        return Storage::disk('files')->url($this->file_path);
    }

    public function isLinked(): bool
    {
        return $this->check_id !== null || $this->transaction_id !== null;
    }

    /**
     * Rows eligible for (re-)matching: never touch manual links or rows that
     * already carry a link.
     */
    public function isMatchable(): bool
    {
        return $this->match_status !== self::STATUS_MANUAL && ! $this->isLinked();
    }
}
