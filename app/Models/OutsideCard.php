<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A person's word on a card from a company's receipts: is_outside = true when
 * it is someone else's card, false ("It's ours") when it is the company's
 * own. Cards nobody has ruled on are judged by OutsideCardService.
 */
class OutsideCard extends Model
{
    /** @use HasFactory<\Database\Factories\OutsideCardFactory> */
    use HasFactory;

    protected $fillable = [
        'belongs_to_vendor_id',
        'last_four',
        'is_outside',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'is_outside' => 'boolean',
        ];
    }

    /** "Not GS Construction" — the company's short name, else its business name. */
    public static function labelFor(int $belongsToVendorId): string
    {
        $vendor = Vendor::withoutGlobalScopes()->find($belongsToVendorId);
        $name = trim((string) ($vendor?->short_name ?: $vendor?->business_name ?: 'the company'));

        return 'Not '.$name;
    }
}
