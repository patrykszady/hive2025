<?php

namespace App\Models;

use App\Models\Scopes\BankAccountScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BankAccount extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected static function booted()
    {
        static::addGlobalScope(new BankAccountScope);
    }

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

    public function getNameAndType()
    {
        return $this->bank->name.' | '.$this->type;
    }

    //4-11-2022 accout_number setter... if 3 digits, add 0 in front, if 4 ignore
}
