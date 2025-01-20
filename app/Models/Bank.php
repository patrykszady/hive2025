<?php

namespace App\Models;

use App\Models\Scopes\BankScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Bank extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'plaid_access_token', 'plaid_item_id', 'vendor_id', 'plaid_ins_id', 'plaid_options', 'created_at', 'updated_at', 'deleted_at'];

    // protected $casts = [
    //     'plaid_options' => 'array',
    // ];

    protected static function booted()
    {
        static::addGlobalScope(new BankScope);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(BankAccount::class);
    }

    // public function transactions()
    // {
    //     return $this->hasManyThrough(Transaction::class, BankAccount::class);
    // }

    public function getPlaidOptionsAttribute($value)
    {
        if ($value == null) {
            $plaid_options = null;
        } else {
            $plaid_options = json_decode($value);
        }

        return $plaid_options;
    }

    // public function getErrorAttribue($value)
    // {
    //     return 'error';
    //     dd($this->plaid_options->error);
    //     if($this->plaid_options->error != FALSE){
    //         return $this->plaid_options->error->error_code;
    //     }else{
    //         return FALSE;
    //     }

    //     return $error;
    // }

    // public function getLastSuccessfulUpdate()
    // {
    //     if(isset($this->plaid_options->transactions->last_successful_update)){
    //         $date = Carbon::parse($this->plaid_options->transactions->last_successful_update);
    //     }else{
    //         $date = false;
    //     }

    //     return $date;
    // }
}
