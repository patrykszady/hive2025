<?php

namespace App\Models;

use App\Scopes\TransactionScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;
use Illuminate\Support\Facades\Cache;

class Transaction extends Model
{
    use HasFactory, Searchable, SoftDeletes;

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
            
            // Add custom filters if any
            if (!empty($filterConditions)) {
                $filterString = implode(' AND ', $filterConditions);
                $options['filter'] = "({$baseFilter}) AND ({$filterString})";
            } else {
                $options['filter'] = $baseFilter;
            }
            
            // Apply sorting
            $options['sort'] = [$sortBy . ':' . $sortDirection];
            
            // Use 'all' matching strategy for exact prefix matching
            if (!empty($searchQuery)) {
                $options['matchingStrategy'] = 'all';
            }
            
            return $meilisearch->search($searchQuery, $options);
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
