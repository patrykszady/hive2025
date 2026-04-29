<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Product extends Model
{
    protected $fillable = [
        'manufacturer',
        'mpn',
        'mpn_normalized',
        'name',
        'description',
        'product_url',
        'image_url',
        'vendor_id',
        'source',
        'verified_at',
        'last_checked_at',
    ];

    protected function casts(): array
    {
        return [
            'verified_at'     => 'datetime',
            'last_checked_at' => 'datetime',
        ];
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public static function normalizeMpn(?string $mpn): string
    {
        return strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string) $mpn));
    }

    public static function lookup(?string $manufacturer, ?string $mpn): ?self
    {
        $norm = self::normalizeMpn($mpn);
        if ($norm === '') {
            return null;
        }

        return self::query()
            ->where('mpn_normalized', $norm)
            ->whereNotNull('product_url')
            ->whereNotNull('image_url')
            ->when($manufacturer, fn ($q) => $q->where(function ($q) use ($manufacturer) {
                $q->whereRaw('LOWER(manufacturer) = ?', [strtolower($manufacturer)])
                    ->orWhereNull('manufacturer');
            }))
            ->orderByDesc('verified_at')
            ->orderByDesc('updated_at')
            ->first();
    }
}
