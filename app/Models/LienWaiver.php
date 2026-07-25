<?php

namespace App\Models;

use App\Enums\LienWaiverStatus;
use App\Enums\LienWaiverType;
use App\Observers\LienWaiverObserver;
use App\Scopes\LienWaiverScope;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

#[ObservedBy([LienWaiverObserver::class])]
class LienWaiver extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'belongs_to_vendor_id',
        'vendor_id',
        'project_id',
        'sworn_statement_id',
        'check_id',
        'payment_id',
        'type',
        'status',
        'amount',
        'exceptions_amount',
        'through_date',
        'jurisdiction',
        'document_hash',
        'draft_path',
        'signed_path',
        'notes',
        'sent_at',
        'signed_at',
        'access_token',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'exceptions_amount' => 'decimal:2',
            'through_date' => 'date:Y-m-d',
            'sent_at' => 'datetime',
            'signed_at' => 'datetime',
            'type' => LienWaiverType::class,
            'status' => LienWaiverStatus::class,
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new LienWaiverScope);

        static::creating(function (LienWaiver $waiver): void {
            if (empty($waiver->access_token)) {
                $waiver->access_token = Str::random(48);
            }
        });
    }

    // Relationships -----------------------------------------------------------

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function payerVendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'belongs_to_vendor_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function check(): BelongsTo
    {
        return $this->belongsTo(Check::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    // Domain helpers ----------------------------------------------------------

    public function isSigned(): bool
    {
        return $this->status === LienWaiverStatus::Signed;
    }

    public function isDraft(): bool
    {
        return $this->status === LienWaiverStatus::Draft;
    }

    /**
     * A waiver from a subcontractor/supplier (claimant is not the paying
     * contractor). Sub waivers print the waiver section only — the contractor's
     * affidavit is a GC→owner document.
     */
    public function isSubWaiver(): bool
    {
        return $this->vendor_id !== null
            && $this->belongs_to_vendor_id !== null
            && (int) $this->vendor_id !== (int) $this->belongs_to_vendor_id;
    }

    public function typeLabel(): string
    {
        return $this->type?->label() ?? '';
    }

    public function statusLabel(): string
    {
        return $this->status?->label() ?? '';
    }

    /**
     * Compute a deterministic hash of the canonical waiver content for tamper
     * detection. Anything that materially changes the legal document should be
     * included in this fingerprint.
     */
    public function computeDocumentHash(): string
    {
        return hash('sha256', json_encode([
            'id' => $this->id,
            'belongs_to_vendor_id' => $this->belongs_to_vendor_id,
            'vendor_id' => $this->vendor_id,
            'project_id' => $this->project_id,
            'check_id' => $this->check_id,
            'payment_id' => $this->payment_id,
            'type' => $this->type?->value,
            'amount' => (string) $this->amount,
            'exceptions_amount' => (string) $this->exceptions_amount,
            'through_date' => $this->through_date?->toDateString(),
            'jurisdiction' => $this->jurisdiction,
        ]));
    }
}
