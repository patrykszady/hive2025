<?php

namespace App\Livewire\Expenses;

use App\Models\Bank;
use App\Models\Distribution;
use App\Models\Expense;
use App\Models\Project;
use App\Models\Transaction;
use App\Models\Vendor;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Lazy]
class ExpenseIndex extends Component
{
    use AuthorizesRequests, WithPagination;

    public $amount = '';

    public $expense_vendor = '';

    public $project = '';

    public $check = '';

    public $bank_plaid_ins_id = '';

    public $banks = [];

    public $bank_account_ids = [];
    // public $bank_owners = [];
    // public $bank_owner = NULL;

    public $status = null;

    public $view = null;

    public $paginate_number = 8;

    public $sortBy = 'date';

    public $sortDirection = 'desc';

    protected $listeners = ['refreshComponent' => '$refresh'];

    protected $queryString = [
        'amount' => ['except' => ''],
        'project' => ['except' => ''],
        // 'check' => ['except' => ''],
        'expense_vendor' => ['except' => ''],
        'bank_plaid_ins_id' => ['except' => ''],
        'bank_owner' => ['except' => ''],
        'status' => ['except' => ''],
    ];

    public function updating()
    {
        $this->resetPage('expenses-page');
        $this->resetPage('transactions-page');
    }

    // public function updated($field, $value)
    // {
    //     dd($field, $value);
    //     // && $value == 'NO_PROJECT'
    //     // if($field == 'project'){
    //     //     $this->expense_vendor = NULL;
    //     // }

    //     // if($field == 'vendor'){
    //     //     $this->project = NULL;
    //     // }
    // }

    public function mount()
    {
        $this->authorize('viewAny', Expense::class);

        if (! is_null($this->view)) {
            $this->paginate_number = 5;
        }

        // $this->banks = Bank::with('accounts')->get()->groupBy('plaid_ins_id')
        //     ->each(function ($banks, $bank_plaid_ins_id) {
        //         $this->bank_account_ids[$bank_plaid_ins_id] = [];
        //         foreach ($banks as $bank) {
        //             array_push($this->bank_account_ids[$bank_plaid_ins_id], $bank->accounts->pluck('id')->toArray());
        //         }

        //         $this->bank_account_ids[$bank_plaid_ins_id] = array_merge(...$this->bank_account_ids[$bank_plaid_ins_id]);
        //     })
        //     ->toBase();

    }

    public function sort($column)
    {
        if ($this->sortBy === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDirection = 'asc';
        }
    }

    #[Computed]
    public function distributions()
    {
        return Distribution::all(['id', 'name']);
    }

    #[Computed]
    public function projects()
    {
        return Project::whereHas('expenses')->orderBy('created_at', 'DESC')->get();
    }

    #[Computed]
    public function vendors()
    {
        return Vendor::whereHas('expenses')->orWhereHas('transactions')->orderBy('business_name')->get();
    }

    #[Computed]
    public function expenses()
    {
        $expenses = Expense::search($this->amount)
            ->where('belongs_to_vendor_id', auth()->user()->primary_vendor_id)
            ->when(! empty($this->expense_vendor) && $this->expense_vendor != '0', function ($query, $item) {
                return $query->where('vendor_id', $this->expense_vendor);
            })
            ->when($this->expense_vendor == '0', function ($query, $item) {
                return $query->where('vendor_id', '0');
            })

            // && $this->project != 'NO_PROJECT' && $this->project != 'SPLIT'
            ->when(! empty($this->project) && is_numeric($this->project), function ($query, $item) {
                return $query->where('project_id', $this->project);
            })
            //and no splits
            ->when($this->project == 'NO_PROJECT', function ($query, $item) {
                return
                    $query
                        ->where('is_project_id_null', 'true')
                        ->where('is_distribution_id_null', 'true')
                        ->where('has_splits', 'false');
            })
            ->when($this->project == 'SPLIT', function ($query, $item) {
                return $query->where('has_splits', 'true');
            })
            ->when(substr($this->project, 0, 1) == 'D', function ($query) {
                return
                    $query
                        ->where('is_distribution_id_null', 'false')
                        ->where('distribution_id', substr($this->project, 2));
            })
            ->when(! empty($this->check) && is_numeric($this->check), function ($query, $item) {
                return $query->where('check_id', $this->check);
            })
            // ->whereIn(
            //     'expense_status', ['Complete', 'Missing Info', 'No Project', 'No Transaction']
            // )
            // ->orderBy('date', 'desc')
            ->tap(fn ($query) => $this->sortBy ? $query->orderBy($this->sortBy, $this->sortDirection) : $query)
            // ->take(10)->get();
            // dd($expenses);
            // ->simplePaginate($paginate_number, ['*'], 'expenses_page');
            ->paginate($this->paginate_number, pageName: 'expenses-page');

        $expenses->getCollection()->each(function ($expense, $key) {
            // if($expense->check){
            //     if($expense->check->transactions->isNotEmpty() && $expense->paid_by != NULL){
            //         $expense->status = 'Complete';
            //     }else{
            //         if($expense->transactions->isNotEmpty()){
            //             $expense->status = 'Complete';
            //         }else{
            //             $expense->status = 'No Transaction';
            //         }
            //     }
            // }else
            if (($expense->transactions->isNotEmpty() && $expense->project->project_name != 'NO PROJECT') || ($expense->paid_by != null && $expense->project->project_name != 'NO PROJECT')) {
                $expense->status = 'Complete';
            } else {
                if ($expense->project->project_name != 'NO PROJECT' && $expense->transactions->isEmpty()) {
                    $expense->status = 'No Transaction';
                } elseif ($expense->project->project_name == 'NO PROJECT' && ($expense->transactions->isNotEmpty() || $expense->paid_by != null)) {
                    $expense->status = 'No Project';
                } else {
                    $expense->status = 'Missing Info';
                }
            }
        });

        // dd($expenses);

        return $expenses;
    }

    #[Computed]
    public function transactions()
    {
        $transactions =
            Transaction::search($this->amount)
                ->where('is_expense_id_null', true)
                ->where('is_check_id_null', true)
                ->whereIn('deposit', ['NOT_DEPOSIT', 'NO_PAYMENTS'])
                ->when(! empty($this->expense_vendor) && $this->expense_vendor != '0', function ($query, $item) {
                    return $query->where('vendor_id', $this->expense_vendor);
                })
                ->when($this->expense_vendor == '0', function ($query, $item) {
                    return $query->where('vendor_id', '0');
                })
                // ->when(!empty($this->bank_plaid_ins_id), function ($query, $item) {
                //     return $query->whereIn('bank_account_id', $this->bank_account_ids[$this->bank_plaid_ins_id]);
                // })
                // ->when(!empty($this->expense_vendor), function ($query, $item) {
                //     return $query->where('vendor_id', $this->expense_vendor);
                // })

                ->orderBy('transaction_date', 'DESC')
                ->paginate(100, pageName: 'transactions-page');

        return $transactions;
    }

    #[Title('Expenses')]
    public function render()
    {
        return view('livewire.expenses.index');
    }
}
