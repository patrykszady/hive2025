<?php

namespace App\Models;

use App\Scopes\BankScope;
use App\Scopes\BankAccountScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Bank extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'plaid_access_token', 'plaid_item_id', 'vendor_id', 'plaid_ins_id', 'plaid_options', 'created_at', 'updated_at', 'deleted_at'];

    protected $casts = [
        'plaid_options' => 'array',
    ];

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

    /**
     * All banks that belong to the same vendor (convenience relationship).
     */
    public function vendor_banks(): HasMany
    {
        return $this->hasMany(Bank::class, 'vendor_id', 'vendor_id');
    }

    /**
     * Relationship: all bank accounts that belong to ANY bank with the same
     * plaid_ins_id AND the same vendor_id as this bank. This disables the
     * global vendor_id scopes on Bank & BankAccount to prevent ambiguous
     * column references in the generated SQL, then reapplies the constraints
     * in a fully-qualified form.
     */
    public function institution_accounts(): HasManyThrough
    {
        return $this->hasManyThrough(
            BankAccount::class, // final
            Bank::class,        // through
            'vendor_id',        // banks.vendor_id (foreign key on banks)
            'bank_id',          // bank_accounts.bank_id (foreign key on bank_accounts)
            'vendor_id',        // local key on current bank (vendor_id)
            'id'                // local key on banks
        )
            // Remove vendor_id global scopes so we can reapply with fully-qualified names
            ->withoutGlobalScopes([BankScope::class, BankAccountScope::class])
            // Scope to matching institution & vendor (both sides qualified)
            ->where('banks.plaid_ins_id', $this->plaid_ins_id)
            ->where('banks.vendor_id', $this->vendor_id)
            ->where('bank_accounts.vendor_id', $this->vendor_id);
    }

    // Custom accessor for the "error" attribute
    public function getErrorAttribute()
    {
        // Check if plaid_options['error']['error_code'] exists
        if ($this->plaid_options['error']['error_code'] ?? false) {
            return $this->plaid_options['error'];
        }

        // Return false if no error exists
        return false;
    }

    // public function transactions()
    // {
    //     return $this->hasManyThrough(Transaction::class, BankAccount::class);
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
