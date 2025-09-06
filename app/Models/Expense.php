<?php

namespace App\Models;

use App\Scopes\ExpenseScope;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;

class Expense extends Model
{
    use HasFactory, Searchable, SoftDeletes;

    protected $fillable = ['amount', 'date', 'invoice', 'note', 'project_id', 'distribution_id', 'vendor_id', 'check_id', 'reimbursment', 'belongs_to_vendor_id', 'created_by_user_id', 'paid_by', 'created_at', 'updated_at', 'deleted_at'];

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
        ];
    }

    // Add this private method to calculate status for indexing
    private function calculateStatus(): string
    {
        // Determine "No Project" using DB fields to avoid relation hydration
        $noProject = (is_null($this->project_id) || (int) $this->project_id === 0)
            && is_null($this->distribution_id)
            && ! $this->splits()->exists();

        // A transaction can be directly attached to the expense or via a related check
        $hasTransactions = $this->transactions()->exists()
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
    public static function scopedSearch($query = '', $filterConditions = [], $sortBy = 'date', $sortDirection = 'desc')
    {
    return self::search($query, function ($meilisearch, $searchQuery, $options) use ($filterConditions, $sortBy, $sortDirection) {
            // Apply base security filters
            $user = auth()->user();
            $baseFilter = "__soft_deleted = 0 AND belongs_to_vendor_id = {$user->vendor->id}";
            
            // Add role-specific filter
            if ($user->vendor_role === 'Member') {
                $baseFilter .= " AND paid_by = {$user->id}";
            }
            
            // Add custom filters if any
            if (!empty($filterConditions)) {
                $filterString = implode(' AND ', $filterConditions);
                $options['filter'] = "({$baseFilter}) AND ({$filterString})";
            } else {
                $options['filter'] = $baseFilter;
            }
            
            // Always apply sorting to maintain order
            $options['sort'] = [$sortBy . ':' . $sortDirection];
            
            // Use 'all' matching strategy for exact prefix matching
            if (!empty($searchQuery)) {
                $options['matchingStrategy'] = 'all';
            }
            
            return $meilisearch->search($searchQuery, $options);
        })
        // Narrow the columns fetched from the database when hydrating models
        ->query(function ($eloquent) {
            $eloquent->select(['id', 'amount', 'date', 'vendor_id', 'project_id', 'distribution_id', 'check_id', 'paid_by']);
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)
        
        ->withDefault(function ($project, $expense) {
            // Prefer attributes provided by search payload to avoid extra queries
            $hasSplitsAttr = array_key_exists('has_splits', $expense->getAttributes()) ? (bool) $expense->getAttribute('has_splits') : null;
            $hasSplits = $hasSplitsAttr !== null ? $hasSplitsAttr : ($expense->relationLoaded('splits') ? $expense->splits->isNotEmpty() : $expense->splits()->exists());

            if ($hasSplits) {
                $project->project_name = 'EXPENSE SPLIT';
                return;
            }

            // If a distribution is present, prefer already-loaded relation; otherwise avoid extra hit when only ID is known
            if (! is_null($expense->distribution_id)) {
                if ($expense->relationLoaded('distribution') && $expense->distribution) {
                    $project->project_name = $expense->distribution->name;
                } elseif (is_array($expense->distribution)) {
                    $project->project_name = $expense->distribution['name'] ?? 'Distribution';
                } else {
                    // Fallback label without triggering a query
                    $project->project_name = 'Distribution';
                }
                $project->distribution = true;
                return;
            }

            $project->project_name = 'NO PROJECT';
        });
    }

    public function check(): BelongsTo
    {
        return $this->belongsTo(Check::class)->with('expenses');
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

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(ExpenseReceipts::class);
    }

    public function associated(): HasMany
    {
        return $this->hasMany(Expense::class, 'id', 'parent_expense_id');
    }

    public function getAssociatedExpensesAttribute()
    {
        if ($this->associated->isEmpty()) {
            $associated_check = Expense::where('parent_expense_id', $this->id)->get();

            if (! $associated_check->isEmpty()) {
                return $associated_check;
            } else {
                return null;
            }
        } else {
            return $this->associated;
        }
    }

    public function getTransactionsAttribute()
    {
        // If the expense has its own transactions, return them
        $own = $this->transactions()->get();
        if ($own->isNotEmpty()) {
            return $own;
        }

        // If the check exists and has transactions, return those
        if ($this->check && $this->check->transactions()->exists()) {
            return $this->check->transactions;
        }

        // Otherwise, return an empty collection
        return collect();
    }

    protected function reimbursment(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => is_numeric($value) ? User::findOrFail($value)->first_name : $value,
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
