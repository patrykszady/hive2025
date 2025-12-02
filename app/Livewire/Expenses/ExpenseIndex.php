<?php

namespace App\Livewire\Expenses;

use App\Models\Distribution;
use App\Models\Expense;
use App\Models\ExpenseSplits;
use App\Models\Project;
use App\Models\Transaction;
use App\Models\Vendor;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

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

    protected $listeners = ['refreshComponent' => '$refresh', 'transaction-used' => 'removeTransaction'];

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
        $this->resetPage('expenses-page');
    }

    public function loadTransactions()
    {
        $this->transactionsReady = true;
    }

    public function removeTransaction($transactionId)
    {
        // This will force a refresh of the transactions computed property
        // which will exclude the transaction since it now has an expense_id
        unset($this->transactions);
        $this->js('window.dispatchEvent(new CustomEvent("remove-transaction-row", { detail: { id: ' . $transactionId . ' } }))');
    }

    #[Computed]
    public function expenses()
    {
        // Removed manual project merging; now handled by Meilisearch filter (project_id OR split_project_ids)

        // Numeric amount search (exact or prefix): include expenses whose own amount OR split amounts match
        if ($this->isNumericAmountSearch()) {
            $perPage = $this->paginate_number;
            $page = \Illuminate\Pagination\Paginator::resolveCurrentPage('expenses-page');
            $mode = $this->amountSearchMode(); // 'exact' | 'prefix'
            $baseExpenses = collect();
            $splitParents = collect();

            if ($mode === 'exact') {
                $searchAmount = round((float) $this->amount, 2);
                
                // Use Scout for exact amount match on parent expenses
                // Add amount filter to filterConditions for exact match
                $filterConditions = $this->buildFilterConditions();
                $filterConditions[] = "amount = {$searchAmount}";
                
                // Pass empty string as search query since we're filtering by amount
                $baseExpenses = Expense::scopedSearch(
                    '',
                    $filterConditions,
                    $this->sortBy,
                    $this->sortDirection
                )->take(10000)->get();

                // Parent expenses whose split equals amount
                $splitParentsQuery = Expense::query()
                    ->select(['id','amount','date','vendor_id','project_id','distribution_id','check_id','paid_by'])
                    ->whereHas('splits', function ($q) use ($searchAmount) {
                        $q->whereRaw('ROUND(amount, 2) = ?', [$searchAmount]);
                    });
            } else { // prefix
                [$min, $upperExclusive] = $this->amountPrefixBounds();
                // Direct Eloquent range query to capture ALL matching amounts regardless of date order
                $user = auth()->user();
                $baseQuery = Expense::query()
                    ->select(['id','amount','date','vendor_id','project_id','distribution_id','check_id','paid_by'])
                    ->where('belongs_to_vendor_id', $user->vendor->id)
                    ->where('amount', '>=', $min)
                    ->where('amount', '<', $upperExclusive);

                // Member role restriction replicating scopedSearch security
                if ($user->vendor_role === 'Member') {
                    $baseQuery->where('paid_by', $user->id);
                }

                // Vendor filter
                if (is_numeric($this->expense_vendor)) {
                    $baseQuery->where('vendor_id', $this->expense_vendor);
                }

                // Project/distribution/split filters (simple subset)
                if (is_numeric($this->project_id)) {
                    $baseQuery->where('project_id', $this->project_id);
                } elseif ($this->project_id === 'SPLIT') {
                    $baseQuery->whereHas('splits');
                } elseif (is_string($this->project_id) && str_starts_with($this->project_id, 'D:')) {
                    $distributionId = (int) substr($this->project_id, 2);
                    $baseQuery->where('distribution_id', $distributionId);
                } elseif ($this->project_id === 'NO_PROJECT') {
                    $baseQuery->where(function ($q) {
                        $q->whereNull('project_id')->orWhere('project_id', 0);
                    })->whereNull('distribution_id')->whereDoesntHave('splits');
                }

                $baseExpenses = $baseQuery->get();

                $splitParentsQuery = Expense::query()
                    ->select(['id','amount','date','vendor_id','project_id','distribution_id','check_id','paid_by'])
                    ->whereHas('splits', function ($q) use ($min, $upperExclusive) {
                        $q->where('amount', '>=', $min)
                          ->where('amount', '<', $upperExclusive);
                    });
            }

            // Apply vendor filter if set
            if (is_numeric($this->expense_vendor)) {
                $splitParentsQuery->where('vendor_id', $this->expense_vendor);
            }

            // Apply distribution filter if user selected a distribution via project selector (D:ID)
            if (is_string($this->project_id) && str_starts_with($this->project_id, 'D:')) {
                $distributionId = (int) substr($this->project_id, 2);
                $splitParentsQuery->where('distribution_id', $distributionId);
            }

            $splitParents = $splitParentsQuery->get();

            // Apply status filters in-memory (like unified path)
            if (! empty($this->expense_statuses)) {
                $allowed = collect($this->expense_statuses);
                $splitParents = $splitParents->filter(fn ($e) => $allowed->contains($e->status));
            }

            $merged = $baseExpenses->concat($splitParents)->unique('id')->sortBy(
                fn ($e) => $e->{$this->sortBy},
                SORT_REGULAR,
                $this->sortDirection === 'desc'
            )->values();

            $total = $merged->count();
            $slice = $merged->forPage($page, $perPage)->values();

            if ($slice->count() > 0) {
                $relations = [];
                if (! in_array($this->view, ['checks.show', 'vendors.show'])) {
                    $relations['vendor'] = fn ($q) => $q->select('id','business_name','business_type');
                }
                if ($this->view !== 'projects.show') {
                    $relations['project'] = fn ($q) => $q->select('id','project_name','address');
                }
                if ($slice->contains(fn ($e) => ! is_null($e->distribution_id))) {
                    $relations['distribution'] = fn ($q) => $q->select('id','name');
                }
                $relations['splits'] = function ($q) {
                    $q->select('id','expense_id','amount','project_id','distribution_id')
                      ->with([
                          'project:id,project_name,address',
                          'distribution:id,name'
                      ]);
                };
                $slice->load($relations);
            }

            $paginator = new LengthAwarePaginator(
                $slice,
                $total,
                $perPage,
                $page,
                ['path' => request()->url()]
            );
            $paginator->setPageName('expenses-page');
            $paginator->appends(request()->except('expenses-page'));
            return $paginator;
        }

        // Default (no project-specific unification)
        $expenses = Expense::scopedSearch(
            $this->amount,
            $this->buildFilterConditions(),
            $this->sortBy,
            $this->sortDirection
        )->paginateWithSearchData($this->paginate_number, pageName: 'expenses-page');

        if ($expenses->count() > 0) {
            $relations = [];
            if (! in_array($this->view, ['checks.show', 'vendors.show'])) {
                $relations['vendor'] = fn ($q) => $q->select('id','business_name','business_type');
            }
            if ($this->view !== 'projects.show') {
                $relations['project'] = fn ($q) => $q->select('id','project_name','address');
            }
            if ($expenses->getCollection()->contains(fn ($e) => ! is_null($e->distribution_id))) {
                $relations['distribution'] = fn ($q) => $q->select('id','name');
            }
            $relations['splits'] = function ($q) {
                $q->select('id','expense_id','amount','project_id','distribution_id')
                  ->with([
                      'project:id,project_name,address',
                      'distribution:id,name'
                  ]);
            };
            $expenses->getCollection()->load($relations);
        }

        return $expenses;
    }

    /**
     * Determine if current amount input should trigger numeric split-amount search.
     */
    private function isNumericAmountSearch(): bool
    {
        $value = $this->normalizedAmountInput();
        if ($value === '') {
            return false;
        }
        return is_numeric($value);
    }

    private function amountSearchMode(): string
    {
        $value = $this->normalizedAmountInput();
        if ($value === '' || ! is_numeric($value)) {
            return 'none';
        }
        if (str_contains($value, '.')) {
            [$int, $dec] = array_pad(explode('.', $value), 2, '');
            $decLength = strlen($dec);
            
            // If less than 2 decimal places, treat as prefix
            if ($decLength < 2) {
                return 'prefix';
            }
            
            // If exactly 2 decimal places, always treat as exact match
            // "112.20" should match only 112.20, "112.25" should match only 112.25
            if ($decLength === 2) {
                return 'exact';
            }
        } elseif (strlen((string) (int) $value) > 0) {
            // Whole number: treat as prefix (e.g., '311' => 311.00 - 311.99)
            return 'prefix';
        }
        return 'exact';
    }

    private function amountPrefixBounds(): array
    {
        $value = $this->normalizedAmountInput();
        $val = (float) $value;
        
        if (str_contains($value, '.')) {
            $dec = substr(strrchr($value, '.'), 1) ?: '';
            $places = strlen($dec);
        } else {
            $places = 0;
        }
        
        if ($places === 0) {
            $min = $val;
            $upperExclusive = $val + 1.0; // [val, val+1.0)
        } elseif ($places === 1) {
            $min = $val;
            // add 0.1 to create exclusive upper bound (covers .X0 - .X9)
            $upperExclusive = round($val + 0.1, 10);
        } else { // shouldn't happen for prefix, fallback
            $min = round($val, 2);
            $upperExclusive = $min + 0.01; // exclusive
        }
        return [$min, $upperExclusive];
    }

    private function normalizedAmountInput(): string
    {
        return trim((string) ($this->amount ?? ''));
    }

    #[Computed]
    public function transactions()
    {        
        // Only vendor & check filters are meaningful for transactions right now
        $filterConditions = [];
        if (is_numeric($this->expense_vendor)) {
            $filterConditions[] = "vendor_id = {$this->expense_vendor}";
        }
        if (is_numeric($this->check)) {
            $filterConditions[] = "check_id = {$this->check}";
        }

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
            // Match either direct project_id or any split containing that project
            $filterConditions[] = "(project_id = {$this->project_id} OR split_project_ids = {$this->project_id})";
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
    
    #[Title('Expenses')]
    public function render()
    {        
        $this->authorize('viewAny', Expense::class);
        return view('livewire.expenses.index');
    }
}
