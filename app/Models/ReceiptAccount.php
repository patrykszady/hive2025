<?php

namespace App\Models;

use App\Scopes\ReceiptAccountScope;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReceiptAccount extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'options' => 'array',
        ];
    }

    public function normalizedOptions(): array
    {
        $options = $this->options;

        if (is_string($options)) {
            $decoded = json_decode($options, true);
            if (is_array($decoded)) {
                $options = $decoded;
            }
        }

        return is_array($options) ? $options : [];
    }

    public function hasAmazonRefreshToken(): bool
    {
        return $this->amazonRefreshToken() !== null;
    }

    public function amazonRefreshToken(): ?string
    {
        $token = $this->normalizedOptions()['refresh_token'] ?? null;

        return is_string($token) && $token !== '' ? $token : null;
    }

    public function amazonAccessToken(): ?string
    {
        $token = $this->normalizedOptions()['access_token'] ?? null;

        return is_string($token) && $token !== '' ? $token : null;
    }

    public function amazonExpiresAt(): ?Carbon
    {
        $expiresIn = $this->normalizedOptions()['expires_in'] ?? null;

        if (! is_string($expiresIn) || $expiresIn === '') {
            return null;
        }

        try {
            return Carbon::parse($expiresIn);
        } catch (\Throwable) {
            return null;
        }
    }

    public function mergeOptions(array $updates): void
    {
        $this->options = array_merge($this->normalizedOptions(), $updates);
        $this->save();
        $this->refresh();
    }

    protected static function booted()
    {
        static::addGlobalScope(new ReceiptAccountScope);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function belongs_to_vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * Receipts associated to this receipt account via shared vendor_id.
     */
    public function receipts(): HasMany
    {
        return $this->hasMany(Receipt::class, 'vendor_id', 'vendor_id');
    }
}
