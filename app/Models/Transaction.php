<?php

namespace App\Models;

use App\Scopes\TransactionScope;
use App\Models\BankAccount;
use App\Traits\HasNumericSearch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;
use Illuminate\Support\Facades\Cache;

class Transaction extends Model
{
    use HasFactory, Searchable, SoftDeletes, HasNumericSearch;

    // protected $dates = ['transaction_date', 'posted_date', 'date', 'deleted_at'];

    protected $guarded = [];

    protected $with = ['vendor', 'bank_account.bank'];

    protected function casts(): array
    {
        return [
            'transaction_date' => 'date:Y-m-d',
            'posted_date' => 'date:Y-m-d',
            'details' => 'array',
        ];
    }

    protected static function booted()
    {
        static::addGlobalScope(new TransactionScope);
        
        // When a transaction is saved or deleted, re-index related expenses
        // This ensures expense status updates when check transactions change
        static::saved(function ($transaction) {
            // Re-index direct expense if linked
            if ($transaction->expense_id) {
                $expense = Expense::withoutGlobalScopes()->find($transaction->expense_id);
                if ($expense) {
                    $expense->searchable();
                }
            }
            
            // Re-index expenses linked via check
            if ($transaction->check_id) {
                $check = Check::withoutGlobalScopes()->find($transaction->check_id);
                if ($check) {
                    foreach ($check->expenses as $expense) {
                        $expense->searchable();
                    }
                }
            }
        });
        
        static::deleted(function ($transaction) {
            // Re-index direct expense if linked
            if ($transaction->expense_id) {
                $expense = Expense::withoutGlobalScopes()->find($transaction->expense_id);
                if ($expense) {
                    $expense->searchable();
                }
            }
            
            // Re-index expenses linked via check
            if ($transaction->check_id) {
                $check = Check::withoutGlobalScopes()->find($transaction->check_id);
                if ($check) {
                    foreach ($check->expenses as $expense) {
                        $expense->searchable();
                    }
                }
            }
        });
    }

    /**
     * Get the name of the index associated with the model.
     */
    public function searchableAs(): string
    {
        return env('APP_ENV') == 'local' ? 'transaction_index_dev' : 'transaction_index';
    }

    // Searchable
    public function toSearchableArray(): array
    {
        $array = $this->toArray();
        $array['transaction_date'] = $this->transaction_date->timestamp;
        $array['posted_date'] = $this->posted_date ? $this->posted_date->timestamp : null;
        $array['deposit'] = $this->deposit ? ($this->payments->isEmpty() ? 'NO_PAYMENTS' : 'HAS_PAYMENTS') : 'NOT_DEPOSIT';
        
        // Ensure amount is consistently cast to float like Expense model
        $array['amount'] = (float) $this->amount;

        return $array;
    }

    /**
     * Create a search builder that respects user access permissions
     * and allows chaining additional filters
     */
    public static function scopedSearch($query = '', $filterConditions = [], $sortBy = 'transaction_date', $sortDirection = 'desc')
    {
        return self::search($query, function ($meilisearch, $searchQuery, $options) use ($filterConditions, $sortBy, $sortDirection) {
            // Get the current user
            $user = auth()->user();
            
            // Get bank account IDs that belong to the current vendor
            $bankAccountIds = Cache::remember("vendor:{$user->vendor->id}:bank_account_ids", 600, function () use ($user) {
                return BankAccount::where('vendor_id', $user->vendor->id)
                    ->pluck('id')
                    ->toArray();
            });
            
            // Convert bank account IDs array to MeiliSearch filter format
            $bankAccountFilter = "bank_account_id IN [" . implode(',', $bankAccountIds) . "]";
            
            // Base filters that apply to all queries
            $baseFilters = [
                "__soft_deleted = 0",
                $bankAccountFilter,
                "expense_id IS NULL",
                "check_id IS NULL",
                'deposit IN ["NOT_DEPOSIT", "NO_PAYMENTS"]',
            ];
            
            $baseFilter = implode(' AND ', $baseFilters);
            
            // Process numeric search queries using shared trait logic
            [$actualQuery, $augmentedFilters] = self::processNumericSearch($searchQuery, $filterConditions);
            
            // Apply search options using shared trait logic
            $options = self::applySearchOptions($options, $baseFilter, $augmentedFilters, $actualQuery, $sortBy, $sortDirection);
            
            return $meilisearch->search($actualQuery, $options);
        });
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
