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

    protected $fillable = ['amount', 'date', 'invoice', 'note', 'categroy_id', 'project_id', 'distribution_id', 'vendor_id', 'check_id', 'reimbursment', 'belongs_to_vendor_id', 'created_by_user_id', 'paid_by', 'created_at', 'updated_at', 'deleted_at'];

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
        return env('APP_ENV') == 'local' ? 'expenses_index_dev' : 'expenses_index';
    }

    public function toSearchableArray(): array
    {
        // All model attributes are made searchable
        $array = $this->toArray();

        // Then we add/adjust some additional fields
        $array['date'] = $this->date->timestamp;
        $array['has_splits'] = $this->splits->isEmpty() ? false : true;

        return $array;
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
        });
    }

    public function project(): BelongsTo
    {
        //1-4-2022 below creates an N + 1 problem
        return $this->belongsTo(Project::class)->withDefault(function ($project, $expense) {
            if ($expense->splits()->exists()) {
                $project->project_name = 'EXPENSE SPLIT';
            } elseif ($expense->distribution) {
                $project->project_name = $expense->distribution->name;
                $project->distribution = true;
            } else {
                $project->project_name = 'NO PROJECT';
                //1/3/2022 else shoud behave as regular belongsTo method with no withDefault()
                // throw new \Exception("Attempt to read property project_name on null");
            }
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

    protected function reimbursment(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => is_numeric($value) ? User::findOrFail($value)->first_name : $value,
        );
    }

    protected function status(): Attribute
    {
        // First check if expense has a check with "Complete" status
        if ($this->check && $this->check->status === 'Complete') {
            return Attribute::make(
                get: fn ($value) => 'Complete'
            );
        }
        
        // Normalize project name for comparison to avoid case sensitivity issues
        $projectName = strtoupper($this->project->project_name);
        
        // Special handling for "NO PROJECT"
        if ($projectName === 'NO PROJECT') {
            return Attribute::make(
                get: fn ($value) => 'No Project'
            );
        }
        
        // Status logic for regular projects
        if ($this->transactions->isNotEmpty() || $this->paid_by !== null) {
            return Attribute::make(
                get: fn ($value) => 'Complete'
            );
        } elseif ($this->transactions->isEmpty()) {
            return Attribute::make(
                get: fn ($value) => 'No Transaction'
            );
        } else {
            return Attribute::make(
                get: fn ($value) => 'Missing Info'
            );
        }
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
