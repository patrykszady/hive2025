<?php

namespace App\Models;

use App\Scopes\TransactionScope;
use App\Models\BankAccount;
use App\Traits\HasNumericSearch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

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

        // Prevent linking a transaction to an expense with a different vendor
        static::saving(function ($transaction) {
            if ($transaction->isDirty('expense_id') && $transaction->expense_id !== null && $transaction->vendor_id !== null) {
                $expense = Expense::withoutGlobalScopes()->find($transaction->expense_id);
                if ($expense && $expense->vendor_id !== null && (int) $expense->vendor_id !== (int) $transaction->vendor_id) {
                    // Allow service fee transactions (small amounts with SERVICEFEE in description)
                    $isServiceFee = $transaction->amount <= 10.00 && str_contains($transaction->plaid_merchant_description ?? '', 'SERVICEFEE');
                    if (!$isServiceFee) {
                        Log::warning('Blocked cross-vendor transaction→expense link', [
                            'transaction_id' => $transaction->id,
                            'transaction_vendor_id' => $transaction->vendor_id,
                            'transaction_amount' => $transaction->amount,
                            'expense_id' => $transaction->expense_id,
                            'expense_vendor_id' => $expense->vendor_id,
                        ]);
                        $transaction->expense_id = null;
                    }
                }
            }
        });
        
        // When a transaction is saved or deleted, re-index related expenses
        // This ensures expense status updates when check transactions change
        static::saved(function ($transaction) {
            // Soft-delete $0 posted (non-pending) transactions from Plaid — they carry no value
            if (
                ! $transaction->trashed()
                && $transaction->posted_date !== null
                && (float) $transaction->amount === 0.00
                && $transaction->plaid_transaction_id !== null
            ) {
                $transaction->delete();

                return;
            }

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
        $array['deposit'] = $this->payments->isNotEmpty()
            ? 'HAS_PAYMENTS'
            : ($this->deposit ? 'NO_PAYMENTS' : 'NOT_DEPOSIT');
        $array['expenses_count'] = $this->expenses()->count();
        
        // Ensure amount is consistently cast to float like Expense model
        $array['amount'] = (float) $this->amount;

        // Only index a real vendor name. The `vendor` relationship returns a
        // "No Vendor" default model when none is attached, which pollutes text
        // search (e.g. typo tolerance fuzzy-matches "Venmo" against "Vendor").
        $array['vendor_name'] = $this->vendor_id ? $this->vendor?->business_name : null;

        // The default vendor stub must never leak into the index via toArray().
        unset($array['vendor']);

        return $array;
    }

    /**
     * Create a search builder that respects user access permissions
     * and allows chaining additional filters
     */
    public static function scopedSearch($query = '', $filterConditions = [], $sortBy = 'transaction_date', $sortDirection = 'desc', bool $textSearch = false)
    {
        return self::search($query, function ($meilisearch, $searchQuery, $options) use ($filterConditions, $sortBy, $sortDirection, $textSearch) {
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
                "expenses_count = 0",
                "check_id IS NULL",
                'deposit IN ["NOT_DEPOSIT", "NO_PAYMENTS"]',
            ];
            
            $baseFilter = implode(' AND ', $baseFilters);
            
            // Process numeric search queries using shared trait logic
            [$actualQuery, $augmentedFilters] = self::processNumericSearch($searchQuery, $filterConditions);
            
            // Apply search options using shared trait logic
            $options = self::applySearchOptions($options, $baseFilter, $augmentedFilters, $actualQuery, $sortBy, $sortDirection);

            // Restrict which fields are searched based on search type
            if ($textSearch && trim($actualQuery) !== '') {
                $options['attributesToSearchOn'] = ['plaid_merchant_description', 'plaid_merchant_name', 'vendor_name'];
            } elseif (!$textSearch && trim($actualQuery) !== '') {
                $options['attributesToSearchOn'] = ['amount'];
            }
            
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

    /**
     * Many-to-many relationship: one transaction can belong to multiple expenses.
     * This is used when a single bank transaction covers multiple expense records.
     */
    public function expenses(): BelongsToMany
    {
        return $this->belongsToMany(Expense::class, 'expense_transaction')
            ->withTimestamps();
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

    /**
     * Plaid's merchant location for this transaction, when the bank provided
     * one. Null when every field is empty — some banks (e.g. Capital One)
     * strip location on many transactions.
     *
     * @return array{city: string, region: string, postal_code: string}|null
     */
    public function plaidLocation(): ?array
    {
        $location = data_get($this->details, 'location');

        if (! is_array($location)) {
            return null;
        }

        $normalized = [
            'city' => trim((string) ($location['city'] ?? '')),
            'region' => trim((string) ($location['region'] ?? '')),
            'postal_code' => substr(preg_replace('/\D/', '', (string) ($location['postal_code'] ?? '')), 0, 5),
        ];

        return array_filter($normalized) === [] ? null : $normalized;
    }

    /**
     * Compare this transaction's Plaid location against a vendor's stored
     * address. Generic same-named merchants ("SMOKE N VAPE", "Chinese Food")
     * can only be told apart by where the charge happened, so vendors with an
     * address act as location-specific and matching gates on agreement.
     *
     * true  => comparable and they agree (zip, or city and state)
     * false => comparable and they disagree — different physical location
     * null  => not comparable (transaction or vendor lacks location data)
     */
    public function locationAgreementWithVendor(Vendor $vendor): ?bool
    {
        $location = $this->plaidLocation();

        if (! $location) {
            return null;
        }

        $vendorZip = substr(preg_replace('/\D/', '', (string) $vendor->zip_code), 0, 5);
        if (strlen($location['postal_code']) === 5 && strlen($vendorZip) === 5) {
            return $location['postal_code'] === $vendorZip;
        }

        $txnCity = mb_strtolower($location['city']);
        $vendorCity = mb_strtolower(trim((string) $vendor->city));
        $txnRegion = mb_strtolower($location['region']);
        $vendorState = mb_strtolower(trim((string) $vendor->state));
        // Regions only compare when both are 2-letter state codes — vendors
        // sometimes store the full state name, which must not read as conflict.
        $regionsComparable = strlen($txnRegion) === 2 && strlen($vendorState) === 2;

        if ($txnCity === '' || $vendorCity === '') {
            if ($regionsComparable && $txnRegion !== $vendorState) {
                return false;
            }

            return null;
        }

        if (! self::citiesAgree($txnCity, $vendorCity)) {
            return false;
        }

        if ($regionsComparable && $txnRegion !== $vendorState) {
            return false;
        }

        return true;
    }

    /**
     * Some banks truncate city names in Plaid data ("Mount" for Mount
     * Prospect, "Buffalo" for Buffalo Grove), so cities agree when equal or
     * when one is a leading whole word of the other. Expects lowercase input.
     */
    protected static function citiesAgree(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }

        [$short, $long] = strlen($a) <= strlen($b) ? [$a, $b] : [$b, $a];

        return str_starts_with($long, $short.' ');
    }

    //used in TransactionController::add_vendor_to_transactions
    //used in Livewire/Transactions/MatchVendor::mount
    public function scopeTransactionsSinVendor($query)
    {
        $query->withoutGlobalScopes()
            ->whereNull('vendor_id')
            ->whereNull('expense_id')
            ->whereNull('deposit')
            ->whereNull('check_number')
            ->whereNull('deleted_at')
            ->whereDoesntHave('payments', function ($query): void {
                $query->withoutGlobalScopes();
            });
    }
}
