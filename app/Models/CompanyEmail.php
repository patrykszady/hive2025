<?php

namespace App\Models;

use App\Scopes\CompanyEmailsScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class CompanyEmail extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'api_json' => 'array',
        ];
    }

    protected static function booted()
    {
        static::addGlobalScope(new CompanyEmailsScope);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * Receipt accounts associated via this company's vendor (matches belongs_to_vendor_id on receipt_accounts).
     * Local key: vendor_id on company_emails
     * Foreign key: belongs_to_vendor_id on receipt_accounts
     */
    public function receipt_accounts(): HasMany
    {
        return $this->hasMany(ReceiptAccount::class, 'belongs_to_vendor_id', 'vendor_id');
    }

    /**
     * Receipts accessible for this company email through its receipt accounts.
     * Chain:
     *   company_emails.vendor_id -> receipt_accounts.belongs_to_vendor_id
     *   receipt_accounts.vendor_id -> receipts.vendor_id
     */
    public function receipts(): HasManyThrough
    {
        return $this->hasManyThrough(
            Receipt::class,
            ReceiptAccount::class,
            'belongs_to_vendor_id', // FK on receipt_accounts referencing company_emails.vendor_id
            'vendor_id',            // FK on receipts referencing receipt_accounts.vendor_id
            'vendor_id',            // Local key on company_emails
            'vendor_id'             // Local key on receipt_accounts
        );
    }
}
