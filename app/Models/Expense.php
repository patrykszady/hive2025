<?php

namespace App\Models;

use App\Scopes\ExpenseScope;
use App\Models\Distribution;
use App\Traits\HasNumericSearch;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Laravel\Scout\Builder as ScoutBuilder;
use Laravel\Scout\Searchable;

class Expense extends Model
{
    use HasFactory, Searchable, SoftDeletes, HasNumericSearch;

    protected $fillable = ['amount', 'date', 'invoice', 'note', 'project_id', 'distribution_id', 'vendor_id', 'check_id', 'parent_expense_id', 'reimbursment', 'belongs_to_vendor_id', 'created_by_user_id', 'paid_by', 'created_at', 'updated_at', 'deleted_at'];

    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
        ];
    }

    protected static function booted()
    {
        static::addGlobalScope(new ExpenseScope);
    }

    /**
     * Get the name of the index associated with the model.
     */
    public function searchableAs(): string
    {
    return app()->environment('local') ? 'expenses_index_dev' : 'expenses_index';
    }

    /**
     * Get the indexable data array for the model.
     */
    public function toSearchableArray(): array
    {
        // Calculate status before indexing
        $status = $this->calculateStatus();
        // Gather split metadata once to avoid N+1 later; lightweight fields only
        $splitProjectIds = [];
        $splitAmounts = [];
        $splitReimbursments = [];
        if ($this->relationLoaded('splits')) {
            $splits = $this->splits;
        } else {
            // Select minimal columns to reduce query cost when indexing
            $splits = $this->splits()->select('id','project_id','amount','reimbursment')->get();
        }
        foreach ($splits as $split) {
            if (! is_null($split->project_id)) {
                $splitProjectIds[] = (int) $split->project_id;
            }
            $splitAmounts[] = (float) $split->amount; // keep raw float for range / prefix strategies client-side later
            if (! is_null($split->reimbursment) && $split->reimbursment !== '' && $split->reimbursment !== 'None') {
                $splitReimbursments[] = $split->reimbursment;
            }
        }

        // Index only the fields we actually use for search, filtering, and sorting
        return [
            'id' => (string) $this->id,
            'amount' => (float) $this->amount,
            'date' => $this->date?->timestamp ?? 0,
            'vendor_id' => $this->vendor_id,
            'project_id' => $this->project_id,
            'distribution_id' => $this->distribution_id,
            'check_id' => $this->check_id,
            'has_splits' => $this->splits()->exists(),
            'expense_status' => $status,
            'belongs_to_vendor_id' => (int) $this->belongs_to_vendor_id,
            'paid_by' => $this->paid_by,
            'reimbursment' => $this->getRawOriginal('reimbursment'),
            // Denormalized split metadata
            'split_project_ids' => $splitProjectIds,
            'split_amounts' => $splitAmounts,
            'split_reimbursments' => array_values(array_unique($splitReimbursments)),
        ];
    }

    // Add this private method to calculate status for indexing
    private function calculateStatus(): string
    {
        if ($this->trashed()) {
            return 'Deleted';
        }

        // Determine "No Project" using DB fields to avoid relation hydration
        $noProject = (is_null($this->project_id) || (int) $this->project_id === 0)
            && is_null($this->distribution_id)
            && ! $this->splits()->exists();

        // A transaction can be directly attached to the expense or via a related check
        $hasTransactions = $this->transactions()->exists()
            || $this->sharedTransactions()->exists()
            || $this->check()->whereHas('transactions')->exists()
            || ! is_null($this->paid_by);

        // Rules:
        // - If project is "No Project" AND no transactions => Missing Info
        if ($noProject && ! $hasTransactions) {
            return 'Missing Info';
        }

        // - If project is "No Project" AND has transactions => No Project
        if ($noProject && $hasTransactions) {
            return 'No Project';
        }

        // - If project is NOT "No Project" AND no transactions => No Transaction
        if (! $noProject && ! $hasTransactions) {
            return 'No Transaction';
        }

        // - If project is NOT "No Project" AND has transactions => Complete
        return 'Complete';
    }

    /**
     * Create a search builder that respects user access permissions
     */
    public static function scopedSearch($query = '', $filterConditions = [], $sortBy = 'date', $sortDirection = 'desc', ?User $user = null, bool $onlyTrashed = false): ScoutBuilder
    {
        $user ??= Auth::user();

        if (! $user) {
            throw new \RuntimeException('Expense::scopedSearch() requires an authenticated user. For scheduler/tenant usage, call Expense::scopedSearchForVendor($belongsToVendorId, ...) instead.');
        }

        $belongsToVendorId = (int) $user->vendor->id;
        $softDeleteFilter = $onlyTrashed ? '__soft_deleted = 1' : '__soft_deleted = 0';
        $baseFilter = "{$softDeleteFilter} AND belongs_to_vendor_id = {$belongsToVendorId}";

        if ($user->vendor_role === 'Member') {
            $paidByUserId = (int) $user->id;
            $baseFilter .= " AND paid_by = {$paidByUserId}";
        }

        return self::scopedSearchWithBaseFilter($query, $filterConditions, $sortBy, $sortDirection, $baseFilter, $onlyTrashed);
    }

    /**
     * Scheduler-safe Meilisearch query builder scoped to a specific hive vendor/tenant.
     */
    public static function scopedSearchForVendor(int $belongsToVendorId, $query = '', $filterConditions = [], $sortBy = 'date', $sortDirection = 'desc'): ScoutBuilder
    {
        $belongsToVendorId = (int) $belongsToVendorId;
        $baseFilter = "__soft_deleted = 0 AND belongs_to_vendor_id = {$belongsToVendorId}";

        return self::scopedSearchWithBaseFilter($query, $filterConditions, $sortBy, $sortDirection, $baseFilter);
    }

    protected static function scopedSearchWithBaseFilter($query, array $filterConditions, string $sortBy, string $sortDirection, string $baseFilter, bool $onlyTrashed = false): ScoutBuilder
    {
        return self::search($query, function ($meilisearch, $searchQuery, $options) use ($filterConditions, $sortBy, $sortDirection, $baseFilter) {
            // Process numeric search queries using shared trait logic
            [$actualQuery, $augmentedFilters] = self::processNumericSearch($searchQuery, $filterConditions);

            // Apply search options using shared trait logic
            $options = self::applySearchOptions($options, $baseFilter, $augmentedFilters, $actualQuery, $sortBy, $sortDirection);

            return $meilisearch->search($actualQuery, $options);
        })
            // Narrow the columns fetched from the database when hydrating models
            ->query(function ($eloquent) use ($onlyTrashed) {
                $columns = ['id', 'amount', 'date', 'vendor_id', 'project_id', 'distribution_id', 'check_id', 'paid_by'];
                if ($onlyTrashed) {
                    $columns[] = 'deleted_at';
                    $eloquent->onlyTrashed();
                }
                $eloquent->select($columns);
            });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)
            ->withDefault(function ($project, $expense) {
                // Determine split state (prefer indexed attribute to avoid additional queries)
                $hasSplitsAttr = array_key_exists('has_splits', $expense->getAttributes()) ? (bool) $expense->getAttribute('has_splits') : null;
                $hasSplits = $hasSplitsAttr !== null
                    ? $hasSplitsAttr
                    : ($expense->relationLoaded('splits') ? $expense->splits->isNotEmpty() : $expense->splits()->exists());

                if ($hasSplits) {
                    $project->split = true; // consumed by Project::name
                    $project->project_name = 'EXPENSE SPLIT';
                    return;
                }

                if (! is_null($expense->distribution_id)) {
                    $project->distribution = true;
                    if ($expense->relationLoaded('distribution') && $expense->distribution) {
                        $project->project_name = $expense->distribution->name;
                        $project->distribution_name = $expense->distribution->name;
                    } else {
                        $distributionName = optional($expense->distribution()->select('id','name')->first())->name;
                        $project->project_name = $distributionName ?? 'Distribution';
                        $project->distribution_name = $project->project_name;
                    }
                    return;
                }
                // else leave project_name unset so Project::name returns No Project
            });
    }

    public function check(): BelongsTo
    {
        return $this->belongsTo(Check::class)->with('expenses');
    }

    public function checks(): BelongsToMany
    {
        return $this->belongsToMany(Check::class, 'check_expense')->withTimestamps();
    }

    public function distribution(): BelongsTo
    {
        return $this->belongsTo(Distribution::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class)->withDefault(function ($expense, $vendor) {
            if ($expense->vendor_id === 0) {
                $vendor->business_name = 'NO VENDOR';
            }
        });
    }

    public function paidby(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function splits(): HasMany
    {
        return $this->hasMany(ExpenseSplits::class);
    }

    /**
     * Primary relationship: transactions linked via the expense_id column.
     * This is the standard 1:1 expense-to-transaction link.
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * Many-to-many relationship for when a single transaction covers multiple expenses.
     * Use this for multi-expense scenarios only (e.g., one $400.84 transaction split across expenses).
     */
    public function sharedTransactions(): BelongsToMany
    {
        return $this->belongsToMany(Transaction::class, 'expense_transaction')
            ->withTimestamps();
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(ExpenseReceipts::class);
    }

    /**
     * Receipts ordered by line items presence (with items first), then by date.
     * Single source of truth for receipt ordering across the app.
     */
    public function orderedReceipts(): HasMany
    {
        return $this->receipts()->ordered();
    }


    public function associated(): HasMany
    {
        return $this->hasMany(Expense::class, 'id', 'parent_expense_id');
    }

    public function getAssociatedExpensesAttribute()
    {
        $results = collect();

        // If this expense has a parent, include the parent
        if ($this->parent_expense_id) {
            $parent = Expense::find($this->parent_expense_id);
            if ($parent) {
                $results->push($parent);
            }

            // Also include siblings (other expenses with the same parent, excluding self)
            $siblings = Expense::where('parent_expense_id', $this->parent_expense_id)
                ->where('id', '!=', $this->id)
                ->get();
            $results = $results->merge($siblings);
        }

        // Include children (expenses that have this expense as their parent)
        $children = Expense::where('parent_expense_id', $this->id)->get();
        $results = $results->merge($children);

        // Include expenses that share the same transaction(s) via pivot table (multi-expense scenarios)
        $sharedTransactionIds = $this->sharedTransactions()->pluck('transactions.id');
        if ($sharedTransactionIds->isNotEmpty()) {
            $sharedExpenses = Expense::whereHas('sharedTransactions', function ($query) use ($sharedTransactionIds) {
                $query->whereIn('transactions.id', $sharedTransactionIds);
            })
                ->where('id', '!=', $this->id)
                ->get();
            $results = $results->merge($sharedExpenses);
        }

        return $results->isNotEmpty() ? $results->unique('id') : null;
    }
    /**
     * Unified accessor for "all" transactions relevant to this expense.
     * Returns this expense's own transactions if present; otherwise falls back
     * to shared transactions (multi-expense), then check transactions; empty collection if none.
     */
    public function allTransactions()
    {
        // First check legacy 1:1 expense_id link
        $own = $this->transactions()->get();
        if ($own->isNotEmpty()) {
            return $own;
        }
        
        // Check many-to-many pivot table (for multi-expense scenarios)
        $shared = $this->sharedTransactions()->get();
        if ($shared->isNotEmpty()) {
            return $shared;
        }
        
        // Check many-to-many checks relationship first
        if ($this->checks()->exists()) {
            $transactions = collect();
            foreach ($this->checks as $check) {
                if ($check->transactions()->exists()) {
                    $transactions = $transactions->merge($check->transactions);
                }
            }
            if ($transactions->isNotEmpty()) {
                return $transactions;
            }
        }
        
        // Fallback to single check relationship for backward compatibility
        if ($this->check && $this->check->transactions()->exists()) {
            return $this->check->transactions;
        }
        
        return collect();
    }

    protected function reimbursment(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                // Treat empty string or whitespace as null
                if ($value === null || (is_string($value) && trim($value) === '')) {
                    return null;
                }
                if (is_numeric($value)) {
                    $u = User::query()->select('id','first_name')->find($value);
                    return $u?->first_name ?? ('User #' . $value);
                }
                return $value;
            },
            set: function ($value) {
                if ($value === null) {
                    return null;
                }
                if (is_string($value) && trim($value) === '') {
                    return null;
                }
                return $value;
            }
        );
    }

    /**
     * Normalize invoice input: if null or blank string, persist as NULL.
     */
    protected function invoice(): Attribute
    {
        return Attribute::make(
            set: function ($value) {
                if ($value === null) {
                    return null;
                }

                if (is_string($value) && trim($value) === '') {
                    return null;
                }

                return $value;
            }
        );
    }

    /**
     * Get the status attribute, preferring indexed value when available
     */
    protected function status(): Attribute
    {
        return Attribute::make(
            get: function ($value, array $attributes) {
                // If it's already set from search results, use that
                if (array_key_exists('expense_status', $attributes)) {
                    return $attributes['expense_status'];
                }
                // Fallback to computed status if not present (avoid heavy relation loads)
                return $this->calculateStatus();
            }
        );
    }

    protected function statusColor(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => match($this->status) {
                'Complete' => 'green',
                'No Transaction' => 'yellow',
                'No Project' => 'red',
                'Missing Info' => 'amber',
                default => 'zinc'
            }
        );
    }
}
