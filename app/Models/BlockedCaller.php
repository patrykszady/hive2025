<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BlockedCaller extends Model
{
    protected $fillable = [
        'phone_number',
        'reason',
        'vendor_id',
        'blocked_by_user_id',
        'auto_blocked',
    ];

    protected function casts(): array
    {
        return [
            'auto_blocked' => 'boolean',
        ];
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function blockedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'blocked_by_user_id');
    }

    /**
     * Check if a phone number is blocked for a given vendor.
     */
    public static function isBlocked(string $phoneNumber, ?int $vendorId = null): bool
    {
        return static::query()
            ->where('phone_number', $phoneNumber)
            ->where(fn ($q) => $q->whereNull('vendor_id')->orWhere('vendor_id', $vendorId))
            ->exists();
    }
}
