<?php

namespace App\Livewire\Expenses;

use App\Models\Distribution;
use App\Models\Expense;
use App\Models\Project;
use App\Models\Transaction;
use App\Models\Vendor;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Cache;

use Livewire\Attributes\Computed;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

// #[Lazy]
class ExpenseIndex extends Component
{
    use AuthorizesRequests, WithPagination;

    public $amount = '';
    public $expense_vendor = null;
    public $project_id = null;

    public $expense_statuses = [];
    public $check = '';

    public $bank_plaid_ins_id = '';

    public $banks = [];
    public $vendors = [];
    public $projects = [];
    public $distributions = [];

    public $bank_account_ids = [];

    public $view = null;

    public $paginate_number = 8;
    public $sortBy = 'date';
    public $sortDirection = 'desc';
    public bool $transactionsReady = false;

    protected $listeners = ['refreshComponent' => '$refresh'];

    protected $queryString = [
        'amount' => ['except' => ''],
        'expense_vendor' => ['except' => ''],
        'project_id' => ['except' => ''],
        'expense_statuses' => ['except' => []],
    ];

    public function updating()
    {
        $this->resetPage('expenses-page');
        $this->resetPage('transactions-page');
    }

    public function mount()
    {
        if (! is_null($this->view)) {
            $this->paginate_number = 5;
        }

        $vendorId = auth()->user()->vendor->id;

        $this->vendors = Cache::remember("filters:v{$vendorId}:vendors", 600, function () {
            return Vendor::whereHas('expenses')
                ->orWhereHas('transactions')
                ->orderBy('business_name')
                ->get(['id', 'business_name', 'business_type']);
        });

        $this->projects = Cache::remember("filters:v{$vendorId}:projects", 600, function () {
            return Project::whereHas('expenses')
                ->orderBy('created_at', 'DESC')
                ->get(['id', 'project_name', 'address']);
        });

        $this->distributions = Cache::remember("filters:v{$vendorId}:distributions", 600, function () {
            return Distribution::all(['id', 'name']);
        });
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

    public function updatedExpenseStatuses($value)
    {
        // Reset pagination when filters change
        $this->resetPage('expenses-page');
    }

    public function loadTransactions(): void
    {
        $this->transactionsReady = true;
    }

    #[Computed]
    public function expenses()
    {
        // Get search results from Scout first
        $expenses = Expense::scopedSearch(
            $this->amount,
            $this->buildFilterConditions(),
            $this->sortBy, 
            $this->sortDirection
        )->paginateWithSearchData($this->paginate_number, pageName: 'expenses-page');
        
        // Then load only the relationships needed for the current view on the collection
        if ($expenses->count() > 0) {
            $relations = [];
            if (! in_array($this->view, ['checks.show', 'vendors.show'])) {
                $relations['vendor'] = function ($q) { $q->select('id', 'business_name', 'business_type'); };
            }
            if ($this->view !== 'projects.show') {
                $relations['project'] = function ($q) { $q->select('id', 'project_name', 'address'); };
            }
            // If any expense has a distribution_id, eager-load distribution to avoid per-item queries
            if ($expenses->getCollection()->contains(fn ($e) => !is_null($e->distribution_id))) {
                $relations['distribution'] = function ($q) { $q->select('id', 'name'); };
            }
            if (! empty($relations)) {
                $expenses->getCollection()->load($relations);
            }
        }

        return $expenses;
    }

    private function buildFilterConditions()
    {
        // Build filter conditions for non-search filters
        $filterConditions = [];
        
        // Add status filters if any are selected
        if (!empty($this->expense_statuses)) {
            $statusFilter = [];
            foreach ($this->expense_statuses as $status) {
                $statusFilter[] = "expense_status = '{$status}'";
            }
            $filterConditions[] = '(' . implode(' OR ', $statusFilter) . ')';
        }
        
        // Add vendor filter
        if (is_numeric($this->expense_vendor)) {
            $filterConditions[] = "vendor_id = {$this->expense_vendor}";
        }
        
        // Handle project filter
        if (is_numeric($this->project_id)) {
            $filterConditions[] = "project_id = {$this->project_id}";
        } elseif ($this->project_id === 'NO_PROJECT') {
            $filterConditions[] = "(project_id = 0 OR project_id IS NULL)";
            $filterConditions[] = "distribution_id IS NULL";
            $filterConditions[] = "has_splits = false";
        } elseif ($this->project_id === 'SPLIT') {
            $filterConditions[] = "has_splits = true";
        } elseif ($this->project_id && substr($this->project_id, 0, 1) === 'D') {
            $distributionId = substr($this->project_id, 2);
            $filterConditions[] = "distribution_id = {$distributionId}";
        }
        
        // Apply check filter if present
        if (is_numeric($this->check)) {
            $filterConditions[] = "check_id = {$this->check}";
        }
        
        return $filterConditions;
    }

    #[Computed]
    public function transactions()
    {        
        // Build filter conditions
        $filterConditions = [];
        
        // Only add vendor filter if a numeric value is selected
        if (is_numeric($this->expense_vendor)) {
            $filterConditions[] = "vendor_id = {$this->expense_vendor}";
        }
        
        // Get paginated results from Scout first
        $transactions = Transaction::scopedSearch(
            $this->amount,
            $filterConditions,
            'transaction_date',
            'desc'
        )->paginate(100, pageName: 'transactions-page');
        
        // Then load the relationships on the collection
        if ($transactions->count() > 0) {
            $transactions->getCollection()->load([
                'vendor:id,business_name',
                'bank_account.bank',
            ]);
        }

        return $transactions;
    }

    #[Title('Expenses')]
    public function render()
    {        
        $this->authorize('viewAny', Expense::class);
        return view('livewire.expenses.index');
    }
}
