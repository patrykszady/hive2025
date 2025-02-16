<?php

namespace App\Models;

use App\Models\Scopes\TransactionScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;

class Transaction extends Model
{
    use HasFactory, Searchable, SoftDeletes;

    // protected $dates = ['transaction_date', 'posted_date', 'date', 'deleted_at'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'transaction_date' => 'date:Y-m-d',
            'posted_date' => 'date:Y-m-d',
            'details' => 'array',
        ];
    }

    protected static function booted()
    {
        static::addGlobalScope(new TransactionScope);
    }

    // //Searchable / Typesense
    public function toSearchableArray(): array
    {
        return array_merge($this->toArray(), [
            'id' => (string) $this->id,
            'amount' => $this->amount,
            'deposit' => (string) $this->deposit ? ($this->payments->isEmpty() ? 'NO_PAYMENTS' : 'HAS_PAYMENTS') : 'NOT_DEPOSIT',
            'vendor_id' => (string) $this->vendor_id,
            'bank_account_id' => (string) $this->bank_account_id,
            'expense_id' => (string) $this->expense_id,
            'is_expense_id_null' => $this->expense_id ? false : true,
            'check_id' => (string) $this->check_id,
            'is_check_id_null' => $this->check_id ? false : true,
            'transaction_date' => $this->transaction_date,
            'posted_date' => $this->posted_date,
            'created_at' => $this->created_at->timestamp,
        ]);
    }

    /**
     * Get the name of the index associated with the model.
     */
    public function searchableAs(): string
    {
        return env('APP_ENV') == 'local' ? 'transaction_index_dev' : 'transaction_index';
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class)->withDefault([
            //if transaction->vendor_id == NULL?
            'business_name' => 'No Vendor',
        ]);
    }

    public function expense(): BelongsTo
    {
        // return $this->belongsTo(Expense::class)->withDefault([
        //     //if transaction->expense_id == NULL?
        //     'id' => 'No Expense',
        // ]);
        return $this->belongsTo(Expense::class);
    }

    public function bank_account(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    // public function accountOwner()
    // {
    //     return $this->hasOneThrough(Bank::class, BankAccount::class);
    // }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function check(): BelongsTo
    {
        return $this->belongsTo(Check::class);
    }

    //bank_accountBank
    // public function bank()
    // {
    //     return $this->hasOneThrough(BankAccount::class, Bank::class);
    // }

    //used in TransactionController::add_vendor_to_transactions
    //used in Livewire/Transactions/MatchVendor::mount
    public function scopeTransactionsSinVendor($query)
    {
        $query->withoutGlobalScopes()
            ->whereNull('vendor_id')
            ->whereNull('deposit')
            ->whereNull('check_number')
            ->whereNull('deleted_at');
    }
}
