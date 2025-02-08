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

    //Searchable / Typesense
    public function toSearchableArray(): array
    {
        // All model attributes are made searchable
        $array = $this->toArray();
        // Then we add/adjust some additional fields
        // $array['created_at'] = $this->created_at->timestamp;
        // $array['updated_at'] = $this->updated_at->timestamp;
        $array['date'] = $this->date->timestamp;
        // $array['expense_status'] = ! is_null($this->project_id) ? 'Complete' : 'Missing Info';

        return $array;

        //ONLY:
        // return [
        //     'id' => $this->id,
        //     'name' => $this->name,
        //     'email' => $this->email,
        // ];




        // if($this->check()->withoutGlobalScopes()){
        //     if($this->check()->withoutGlobalScopes()->transactions->isNotEmpty()){
        //         if($this->check()->withoutGlobalScopes()->transactions->sum('amount') == $this->check()->withoutGlobalScopes()->amount){
        //             $expense_status = 'Complete';
        //         }else{
        //             $expense_status = 'Missing Transaction';
        //         }
        //     }else{
        //         $expense_status = 'No Transaction';
        //     }
        // }
        // if(($this->transactions->isNotEmpty() && $this->project->project_name != 'NO PROJECT') || ($this->paid_by != NULL && $this->project->project_name != 'NO PROJECT')){
        //     $expense_status = 'Complete';
        // }else{
        //     if($this->project->project_name != 'NO PROJECT' && $this->transactions->isEmpty()){
        //         $expense_status = 'No Transaction';
        //     }elseif($this->project->project_name == 'NO PROJECT' && ($this->transactions->isNotEmpty() || $this->paid_by != NULL)){
        //         $expense_status = 'No Project';
        //     }else{
        //         $expense_status = 'Missing Info';
        //     }
        // }

        // return array_merge($this->toArray(), [
        //     'id' => (string) $this->id,
        //     'vendor_id' => (string) $this->vendor_id,
        //     'belongs_to_vendor_id' => (string) $this->belongs_to_vendor_id,
        //     'project_id' => (string) $this->project_id,
        //     'check_id' => (string) $this->check_id,
        //     'is_project_id_null' => $this->project_id ? false : true,
        //     'distribution_id' => (string) $this->distribution_id,
        //     'is_distribution_id_null' => $this->distribution_id ? false : true,
        //     'has_splits' => $this->splits->isEmpty() ? false : true,
        //     'amount' => $this->amount,
        //     'expense_status' => ! is_null($this->project_id) ? 'Complete' : 'Missing Info',
        //     'date' => $this->date->format('Y-m-d'),
        //     'created_at' => $this->created_at->timestamp,
        // ]);
    }

    /**
     * Get the name of the index associated with the model.
     */
    public function searchableAs(): string
    {
        return env('APP_ENV') == 'local' ? 'expenses_index_dev' : 'expenses_index';
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
        // return $this->hasMany(Transaction::class);
        if ($this->check) {
            if ($this->check->transactions) {
                return $this->check->hasMany(Transaction::class);
            } else {
                return $this->hasMany(Transaction::class);
            }
        } else {
            return $this->hasMany(Transaction::class);
        }
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(ExpenseReceipts::class);
    }

    public function associated(): HasMany
    {
        // dd('in Expense.php associated() function');
        return $this->hasMany(Expense::class, 'id', 'parent_expense_id');
    }

    public function getAssociatedExpensesAttribute()
    {
        // dd($this->associated->isEmpty());
        if ($this->associated->isEmpty()) {
            $associated_check = Expense::where('parent_expense_id', $this->id)->get();
            // dd($associated_check);
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
}
