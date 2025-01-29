<?php

namespace App\Models;

use App\Models\Scopes\CompanyEmailsScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function receipt_accounts(): HasMany
    {
        return $this->hasMany(ReceiptAccount::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }
}
