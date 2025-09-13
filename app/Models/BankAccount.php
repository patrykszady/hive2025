<?php

namespace App\Models;

use App\Scopes\BankAccountScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class BankAccount extends Model
{
    use SoftDeletes, HasFactory;

    protected $guarded = [];

    protected static function booted()
    {
        static::addGlobalScope(new BankAccountScope);
    }

    protected $casts = [
        'options' => 'object',
        'options->last_balance_update' => 'date:Y-m-d',
    ];

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }

    public function checks(): HasMany
    {
        return $this->hasMany(Check::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function scopeLatestCheckingAccounts($query)
    {
        return $query->with('bank')
            ->where('type', 'Checking')
            ->whereHas('bank', function ($query) {
                $query->whereNotNull('plaid_access_token');
            })
            ->whereIn('id', function ($subQuery) {
                $subQuery->selectRaw('MAX(id) as id') // Select the latest ID for each bank_id
                    ->from('bank_accounts')
                    ->where('type', 'Checking')
                    ->groupBy('bank_id'); // Group by bank_id to get one account per bank
            });
    }

    // New scope for filtering by subtype/type reuse
    public function scopeOfSubtype($query, string $subtype)
    {
        return $query->where('type', $subtype);
    }

    public function getNameAndType()
    {
        return $this->bank->name.' | '.$this->type;
    }

    //4-11-2022 accout_number setter... if 3 digits, add 0 in front, if 4 ignore
}
