<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A subcontractor's updated estimate or invoice compared against one of our
 * estimates — or, with kind = quote, a window and door supplier's quote
 * imported as a section of the estimate (App\Services\Estimates\
 * SupplierQuoteSectionWriter; `applied` then records the lines written,
 * `markup_percent` what was added to the supplier's list prices). The AI reads its line items (document), matches them to the
 * estimate's catalog items and proposes the change-order additions and
 * credits (proposals); the admin ticks what to apply
 * (App\Services\Estimates\SubDocumentChangeOrderApplier) and the created
 * line item ids are kept in applied.
 */
class EstimateSubDocument extends Model
{
    use HasFactory;

    public const STATUS_EXTRACTING = 'extracting';

    public const STATUS_MATCHED = 'matched';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_FAILED = 'failed';

    /** A sub's invoice compared against the estimate (EstimateSubDocumentCompare). */
    public const KIND_INVOICE = 'invoice';

    /** A window/door supplier's quote imported as an estimate section (EstimateSupplierQuote). */
    public const KIND_QUOTE = 'quote';

    protected $fillable = [
        'estimate_id', 'project_id', 'belongs_to_vendor_id', 'sub_vendor_id', 'uploaded_by_user_id', 'status', 'kind', 'markup_percent', 'estimate_section_id',
        'file_disk', 'file_path', 'original_name', 'mime_type', 'document', 'proposals', 'applied', 'applied_at',
        'model', 'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'document' => 'array',
            'proposals' => 'array',
            'applied' => 'array',
            'applied_at' => 'datetime',
            'markup_percent' => 'float',
        ];
    }

    public function estimate(): BelongsTo
    {
        return $this->belongsTo(Estimate::class)->withoutGlobalScopes();
    }

    public function subVendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'sub_vendor_id')->withoutGlobalScopes();
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    /** The estimate section a supplier quote was imported as. */
    public function section(): BelongsTo
    {
        return $this->belongsTo(EstimateSection::class, 'estimate_section_id')->withTrashed();
    }

    public function items(): HasMany
    {
        return $this->hasMany(EstimateSubDocumentItem::class)->orderBy('index');
    }

    /** "Smartech Electric invoice #0062 (Oct 22)": how the document is referred to. */
    public function label(): string
    {
        $vendor = $this->subVendor?->name ?: data_get($this->document, 'vendor_name') ?: ($this->kind === self::KIND_QUOTE ? 'Supplier' : 'Sub');
        $type = data_get($this->document, 'document_type') ?: 'document';
        $number = data_get($this->document, 'document_number');
        $date = data_get($this->document, 'document_date');

        return trim($vendor.' '.$type.($number ? ' #'.$number : '').($date ? ' ('.date('M j, Y', strtotime($date)).')' : ''));
    }
}
