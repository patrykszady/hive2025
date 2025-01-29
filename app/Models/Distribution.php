<?php

namespace App\Models;

use App\Scopes\DistributionScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Distribution extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected static function booted()
    {
        static::addGlobalScope(new DistributionScope);
    }

    public function getBalancesAttribute($value)
    {
        $balances = json_decode($value);

        return $balances;
    }

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class)->withPivot('percent', 'amount', 'created_at')->withTimestamps();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function receipt_accounts(): HasMany
    {
        return $this->hasMany(ReceiptAccount::class);
    }

    public function transactions_bulk_match(): HasMany
    {
        return $this->hasMany(TransactionBulkMatch::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function splits(): HasMany
    {
        return $this->hasMany(ExpenseSplits::class);
    }
}
