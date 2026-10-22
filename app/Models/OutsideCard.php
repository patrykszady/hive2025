<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A card that shows on a company's receipts but is not the company's own —
 * someone else's card paid it, so no charge for it will ever reach the
 * company's bank accounts. Payments on it count as paid outside the bank
 * (ExpensePayment::paidOutsideBank()), the same way store credit does.
 */
class OutsideCard extends Model
{
    /** @use HasFactory<\Database\Factories\OutsideCardFactory> */
    use HasFactory;

    protected $fillable = [
        'belongs_to_vendor_id',
        'last_four',
        'created_by_user_id',
    ];

    public static function isOutside(int $belongsToVendorId, ?string $lastFour): bool
    {
        if ($lastFour === null || $lastFour === '') {
            return false;
        }

        return self::query()
            ->where('belongs_to_vendor_id', $belongsToVendorId)
            ->where('last_four', $lastFour)
            ->exists();
    }

    /** "Not GS Construction" — the company's short name, else its business name. */
    public static function labelFor(int $belongsToVendorId): string
    {
        $vendor = Vendor::withoutGlobalScopes()->find($belongsToVendorId);
        $name = trim((string) ($vendor?->short_name ?: $vendor?->business_name ?: 'the company'));

        return 'Not '.$name;
    }
}
