<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class VendorTransaction extends Model
{
    use HasFactory;

    /** Cache key of the stamp that changes whenever a rule is saved or deleted. */
    public const RULES_VERSION_KEY = 'vendor_transactions:rules_version';

    protected $fillable = ['vendor_id', 'deposit_check', 'amount_sign', 'plaid_inst_id', 'desc', 'options'];

    /**
     * CompanyEmailController::fuzzyMatchVendor() keeps the rules in memory
     * for the life of its process, and a queue worker lives for hours: a
     * rule saved on Match Vendor (or learned by ReceiptChargePairer) was
     * not seen by the receipt intake until the worker restarted. Every save
     * and delete now changes a stamp in the shared cache, and that memory is
     * only trusted while the stamp it was read under still stands.
     */
    protected static function booted(): void
    {
        static::saved(fn () => self::touchRulesVersion());
        static::deleted(fn () => self::touchRulesVersion());
    }

    /**
     * Whether this rule claims a charge with this text — the bank's own text
     * (plaid_merchant_description) or Plaid's clean merchant name, each tried
     * on its own. The rule's text is a pattern ("\*" for a typed *), with its
     * flags kept in `options`, exactly as the matchers have always built it.
     */
    public function matchesText(?string $text): bool
    {
        $text = (string) $text;

        if ($text === '') {
            return false;
        }

        $matched = @preg_match('/'.$this->desc.json_decode((string) $this->options), $text, $matches, PREG_UNMATCHED_AS_NULL);

        return $matched === 1 && ! empty($matches);
    }

    /**
     * Whether this rule claims the charge: on its bank text, or on Plaid's
     * name for it (Patryk, 2026-11-13: "Match As" is often typed as the
     * clean Plaid name, "Frontier Communications", and "that should work in
     * Match As as well as the bank text").
     */
    public function matchesCharge(?string $description, ?string $merchantName): bool
    {
        return $this->matchesText($description) || $this->matchesText($merchantName);
    }

    public static function rulesVersion(): string
    {
        return (string) Cache::get(self::RULES_VERSION_KEY, '0');
    }

    public static function touchRulesVersion(): void
    {
        Cache::forever(self::RULES_VERSION_KEY, (string) Str::uuid());
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class)->withoutGlobalScopes();
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class, 'plaid_inst_id', 'plaid_ins_id')->withoutGlobalScopes();
    }

    public function setDescAttribute($value)
    {
        $this->attributes['desc'] = trim(addcslashes($value, '/'));
    }
}
