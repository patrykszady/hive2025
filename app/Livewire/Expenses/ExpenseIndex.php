<?php

namespace App\Livewire\Expenses;

use App\Jobs\FetchAmazonReceiptForExpense;
use App\Models\Distribution;
use App\Models\Expense;
use App\Models\ExpenseReceipts;
use App\Models\ExpenseSplits;
use Carbon\Carbon;
use App\Models\Project;
use Flux\DateRange;
use App\Models\Transaction;
use App\Models\Vendor;
use App\Models\Check;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class ExpenseIndex extends Component
{
    use AuthorizesRequests, WithPagination;

    #[Url(except: '')]
    public $amount = '';

    #[Url(except: null)]
    public $expense_vendor = null;

    #[Url(except: null)]
    public $project_id = null;

    #[Url(except: [])]
    public $expense_statuses = [];

    #[Url(except: '')]
    public string $receipt_search = '';

    #[Url(except: '')]
    public $reimbursement_filter = '';

    public ?DateRange $date_range = null;

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
    public string $transaction_search = '';
    public bool $transactionsReady = false;
    public array $removedTransactionIds = [];
    public array $matchedReceiptItems = [];
    public string $upcProductName = '';

    protected $listeners = ['refreshComponent' => '$refresh'];

    public function updating()
    {
        $this->resetPage('expenses-page');
        $this->resetPage('transactions-page');
    }

    public function updatedReimbursementFilter($value): void
    {
        if ($value === null) {
            $this->reimbursement_filter = '';
        }
    }

    public function detachExpenseFromCheck(int $expenseId): void
    {
        if (! is_numeric($this->check)) {
            return;
        }

        $expense = Expense::findOrFail($expenseId);
        $this->authorize('update', $expense);

        if ((int) $expense->check_id === (int) $this->check) {
            $expense->check_id = null;
            $expense->save();
        } else {
            DB::table('check_expense')
                ->where('check_id', $this->check)
                ->where('expense_id', $expense->id)
                ->delete();
        }

        $check = Check::find($this->check);
        if ($check) {
            $expenseSum = $check->expenses
                ->concat($check->expensesMany)
                ->unique('id')
                ->sum('amount');
            $check->amount = $expenseSum + $check->timesheets->sum('amount');
            $check->save();
        }

        $this->dispatch('refreshComponent')->to('checks.check-show');
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

        if ($this->view === 'projects.show' && $this->project_id) {
            $this->vendors = Vendor::whereHas('expenses', function ($q) {
                $q->where('project_id', $this->project_id);
            })->orderBy('business_name')->get(['id', 'business_name', 'business_type']);
        }

        $this->projects = Cache::remember("filters:v{$vendorId}:projects", 600, function () {
            return Project::whereHas('expenses')
                ->orderBy('created_at', 'DESC')
                ->get(['id', 'project_name', 'address']);
        });

        if ($this->view === 'vendors.show' && $this->expense_vendor) {
            $this->projects = Project::whereHas('expenses', function ($q) {
                $q->where('vendor_id', $this->expense_vendor);
            })->orderBy('created_at', 'DESC')->get(['id', 'project_name', 'address']);
        }

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

    public function isShowingTrashed(): bool
    {
        return in_array('Deleted', $this->expense_statuses);
    }

    public function restoreExpense(int $expenseId): void
    {
        $expense = Expense::onlyTrashed()->findOrFail($expenseId);
        $this->authorize('restore', $expense);

        $expense->restore();

        if ($expense->vendor_id === 54 && ! $expense->receipts()->exists()) {
            FetchAmazonReceiptForExpense::dispatch($expense);
        }

        unset($this->expenses);
    }

    public function loadTransactions()
    {
        $this->transactionsReady = true;
    }

    #[On('transaction-used')]
    public function removeTransaction(int $transactionId): void
    {
        $this->removedTransactionIds[] = $transactionId;
        unset($this->transactions);
    }

    #[Computed]
    public function via_vendor_employees()
    {
        return auth()->user()->vendor->users()->employed()->wherePivotNotNull('via_vendor_id')->get();
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
                // Add amount filter to filterConditions for exact match (both positive and negative)
                $filterConditions = $this->buildFilterConditions();
                // Search for both positive and negative amounts with same absolute value
                $negativeAmount = -abs($searchAmount);
                $positiveAmount = abs($searchAmount);
                $filterConditions[] = "(amount = {$positiveAmount} OR amount = {$negativeAmount})";
                
                // Pass empty string as search query since we're filtering by amount
                $baseExpenses = Expense::scopedSearch(
                    '',
                    $filterConditions,
                    $this->sortBy,
                    $this->sortDirection,
                    null,
                    $this->isShowingTrashed()
                )->take(10000)->get();

                // Parent expenses whose split equals amount (positive or negative)
                $splitParentsQuery = Expense::query()
                    ->select(['id','amount','date','vendor_id','project_id','distribution_id','check_id','paid_by'])
                    ->whereHas('splits', function ($q) use ($positiveAmount, $negativeAmount) {
                        $q->whereRaw('ROUND(amount, 2) = ? OR ROUND(amount, 2) = ?', [$positiveAmount, $negativeAmount]);
                    });
            } else { // prefix
                [$min, $upperExclusive] = $this->amountPrefixBounds();
                // Direct Eloquent range query to capture ALL matching amounts regardless of date order
                // Include both positive and negative amounts in the range
                $user = auth()->user();
                $selectColumns = ['id','amount','date','vendor_id','project_id','distribution_id','check_id','paid_by'];
                if ($this->isShowingTrashed()) {
                    $selectColumns[] = 'deleted_at';
                }
                $baseQuery = Expense::query()
                    ->select($selectColumns)
                    ->where('belongs_to_vendor_id', $user->vendor->id);

                if ($this->isShowingTrashed()) {
                    $baseQuery->onlyTrashed();
                }

                $baseQuery->where(function ($q) use ($min, $upperExclusive) {
                        // Positive range
                        $q->where(function ($q2) use ($min, $upperExclusive) {
                            $q2->where('amount', '>=', $min)
                               ->where('amount', '<', $upperExclusive);
                        })
                        // Negative range (mirror the positive range)
                        ->orWhere(function ($q2) use ($min, $upperExclusive) {
                            $q2->where('amount', '>', -$upperExclusive)
                               ->where('amount', '<=', -$min);
                        });
                    });

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

                // Date range filter for prefix search (Eloquent path)
                $bounds = $this->dateBounds();
                if ($bounds) {
                    $baseQuery->whereBetween('date', [
                        Carbon::createFromTimestamp($bounds[0])->toDateString(),
                        Carbon::createFromTimestamp($bounds[1])->toDateString(),
                    ]);
                }

                // Reimbursement filter (Eloquent path)
                if ($this->reimbursement_filter !== null && $this->reimbursement_filter !== '') {
                    $filterVal = $this->reimbursement_filter;
                    $baseQuery->where(fn ($q) => $q->where('reimbursment', $filterVal)
                        ->orWhereHas('splits', fn ($sq) => $sq->where('reimbursment', $filterVal)));
                }

                $baseExpenses = $baseQuery->get();

                $splitParentsQuery = Expense::query()
                    ->select(['id','amount','date','vendor_id','project_id','distribution_id','check_id','paid_by'])
                    ->whereHas('splits', function ($q) use ($min, $upperExclusive) {
                        $q->where(function ($q2) use ($min, $upperExclusive) {
                            // Positive range
                            $q2->where(function ($q3) use ($min, $upperExclusive) {
                                $q3->where('amount', '>=', $min)
                                   ->where('amount', '<', $upperExclusive);
                            })
                            // Negative range
                            ->orWhere(function ($q3) use ($min, $upperExclusive) {
                                $q3->where('amount', '>', -$upperExclusive)
                                   ->where('amount', '<=', -$min);
                            });
                        });
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

            // Apply reimbursement filter to split parents
            if ($this->reimbursement_filter !== null && $this->reimbursement_filter !== '') {
                $filterVal = $this->reimbursement_filter;
                $splitParentsQuery->where(fn ($q) => $q->where('reimbursment', $filterVal)
                    ->orWhereHas('splits', fn ($sq) => $sq->where('reimbursment', $filterVal)));
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
                    $q->select('id','expense_id','amount','project_id','distribution_id','reimbursment')
                      ->with([
                          'project:id,project_name,address',
                          'distribution:id,name'
                      ]);
                };
                $relations['receipts'] = fn ($q) => $q->select('id','expense_id','receipt_items')->latest()->limit(1);
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
            $this->sortDirection,
            null,
            $this->isShowingTrashed()
        )->paginateWithSearchData($this->paginate_number, pageName: 'expenses-page');

        // When filtering by check, also include expenses from many-to-many relationship
        if (is_numeric($this->check)) {
            $pivotExpenseIds = \Illuminate\Support\Facades\DB::table('check_expense')
                ->where('check_id', $this->check)
                ->pluck('expense_id');
            
            if ($pivotExpenseIds->isNotEmpty()) {
                $pivotExpenses = Expense::whereIn('id', $pivotExpenseIds)
                    ->where('belongs_to_vendor_id', auth()->user()->vendor->id)
                    ->get();
                
                // Merge with existing collection, avoiding duplicates
                $mergedCollection = $expenses->getCollection()
                    ->concat($pivotExpenses)
                    ->unique('id')
                    ->values();
                
                $expenses->setCollection($mergedCollection);
            }
        }

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
                $q->select('id','expense_id','amount','project_id','distribution_id','reimbursment')
                  ->with([
                      'project:id,project_name,address',
                      'distribution:id,name'
                  ]);
            };
            $relations['receipts'] = fn ($q) => $q->select('id','expense_id','receipt_items')->latest()->limit(1);
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

        // Apply date range filter to transactions
        $bounds = $this->dateBounds();
        if ($bounds) {
            $filterConditions[] = "transaction_date >= {$bounds[0]} AND transaction_date <= {$bounds[1]}";
        }

        $hasTextSearch = trim($this->transaction_search) !== '';

        $transactions = Transaction::scopedSearch(
            $hasTextSearch ? $this->transaction_search : $this->amount,
            $filterConditions,
            'transaction_date',
            'desc',
            $hasTextSearch,
        )->paginate(100, pageName: 'transactions-page');

        // Filter out transactions that were just converted (MeiliSearch may not have indexed yet)
        if (!empty($this->removedTransactionIds)) {
            $removed = $this->removedTransactionIds;
            $transactions->setCollection(
                $transactions->getCollection()->reject(fn ($t) => in_array($t->id, $removed))
            );
        }
        
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
        
        // Add status filters if any are selected (exclude "Deleted" — handled via __soft_deleted)
        $realStatuses = array_filter($this->expense_statuses, fn ($s) => $s !== 'Deleted');
        if (!empty($realStatuses)) {
            $statusFilter = [];
            foreach ($realStatuses as $status) {
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

        // Apply date range filter
        $dateFilter = $this->buildDateFilter();
        if ($dateFilter) {
            $filterConditions[] = $dateFilter;
        }

        // Apply reimbursement filter
        if ($this->reimbursement_filter !== null && $this->reimbursement_filter !== '') {
            $safeValue = addslashes($this->reimbursement_filter);
            $filterConditions[] = "(reimbursment = '{$safeValue}' OR split_reimbursments = '{$safeValue}')";
        }

        // Apply receipt item search filter
        $receiptExpenseIds = $this->getReceiptExpenseIds();
        if ($receiptExpenseIds !== null) {
            if ($receiptExpenseIds->isEmpty()) {
                // No matching receipts — force empty result
                $filterConditions[] = 'id = -1';
            } else {
                $idList = $receiptExpenseIds->implode(', ');
                $filterConditions[] = "id IN [{$idList}]";
            }
        }
        
        return $filterConditions;
    }

    /**
     * Build a Meilisearch date filter from the date_range property.
     * Returns null if no filter, or a filter string like "date >= X AND date <= Y".
     */
    private function buildDateFilter(): ?string
    {
        $bounds = $this->dateBounds();
        if (! $bounds) {
            return null;
        }

        return "date >= {$bounds[0]} AND date <= {$bounds[1]}";
    }

    /**
     * @return array{0: int, 1: int}|null  [startTimestamp, endTimestamp] or null
     */
    private function dateBounds(): ?array
    {
        if (! $this->date_range) {
            return null;
        }

        $start = $this->date_range->start();
        $end = $this->date_range->end();

        if (! $start || ! $end) {
            return null;
        }

        return [$start->copy()->startOfDay()->timestamp, $end->copy()->endOfDay()->timestamp];
    }

    /** @var \Illuminate\Support\Collection|null Cached receipt search expense IDs for the current request */
    private ?\Illuminate\Support\Collection $receiptExpenseIds = null;

    private bool $receiptSearchExecuted = false;

    /**
     * Run the receipt search once per request, populating both the matched items
     * for display and the expense IDs for filtering.
     *
     * If the query looks like a UPC barcode and yields no Meilisearch results,
     * it falls back to a UPC database API lookup, extracts SKU/product name,
     * then re-searches by SKU (which matches receipt ProductCodes) or product name.
     */
    private function executeReceiptSearch(): void
    {
        if ($this->receiptSearchExecuted) {
            return;
        }

        $this->receiptSearchExecuted = true;
        $this->upcProductName = '';

        $query = trim($this->receipt_search);
        if ($query === '') {
            $this->matchedReceiptItems = [];
            $this->receiptExpenseIds = null;

            return;
        }

        $vendorId = (int) auth()->user()->vendor->id;

        $hits = $this->searchReceipts($query, $vendorId);

        // If no results and the query looks like a UPC barcode, try UPC API lookup
        $resolvedSku = null;
        if ($hits->isEmpty() && $this->looksLikeBarcode($query)) {
            $upcData = $this->lookupUpc($query);

            if ($upcData) {
                $this->upcProductName = $upcData['label'];
                $resolvedSku = $upcData['sku'];

                // Try SKU first (matches receipt ProductCodes directly)
                if ($upcData['sku']) {
                    $hits = $this->searchReceipts($upcData['sku'], $vendorId);
                }

                // Fall back to product title if SKU search found nothing
                if ($hits->isEmpty() && $upcData['title']) {
                    $hits = $this->searchReceipts($upcData['title'], $vendorId);
                }
            }
        }

        // Group matched items by expense_id, filtering to only matching items
        $this->matchedReceiptItems = $hits
            ->filter(fn ($hit) => ! empty($hit['expense_id']))
            ->groupBy('expense_id')
            ->map(function ($group) use ($resolvedSku) {
                $best = $group->sortByDesc(fn ($hit) => strlen($hit['_formatted']['descriptions'] ?? $hit['descriptions'] ?? ''))->first();

                $descriptions = $best['_formatted']['descriptions'] ?? $best['descriptions'] ?? '';
                $productCodes = $best['_formatted']['product_codes'] ?? $best['product_codes'] ?? '';
                $rawProductCodes = $best['product_codes'] ?? '';

                // When we have a resolved SKU from UPC lookup, filter to only the matching item
                if ($resolvedSku) {
                    $descs = explode(' | ', $best['descriptions'] ?? '');
                    $codes = explode(' ', $rawProductCodes);
                    $filteredDescs = [];
                    $filteredCodes = [];

                    foreach ($codes as $i => $code) {
                        if (trim($code) === $resolvedSku) {
                            $filteredDescs[] = $descs[$i] ?? '';
                            $filteredCodes[] = '<mark>' . $code . '</mark>';
                        }
                    }

                    // If this receipt doesn't contain the exact SKU, exclude it entirely
                    if (empty($filteredDescs)) {
                        return null;
                    }

                    $descriptions = implode(' | ', $filteredDescs);
                    $productCodes = implode(' ', $filteredCodes);
                }

                return [[
                    'descriptions' => $descriptions,
                    'product_codes' => $productCodes,
                    'purchase_order' => $best['purchase_order'] ?? '',
                    'merchant_name' => $best['merchant_name'] ?? '',
                ]];
            })
            ->filter() // Remove null entries (receipts without the exact SKU)
            ->all();

        $this->receiptExpenseIds = collect($this->matchedReceiptItems)
            ->keys()
            ->values();
    }

    /**
     * Run a Meilisearch query against the receipt index.
     */
    private function searchReceipts(string $query, int $vendorId): \Illuminate\Support\Collection
    {
        $results = ExpenseReceipts::search($query, function ($meilisearch, $searchQuery, $options) use ($vendorId) {
            $options['filter'] = "belongs_to_vendor_id = {$vendorId}";
            $options['limit'] = 10000;
            $options['attributesToRetrieve'] = ['expense_id', 'descriptions', 'product_codes', 'purchase_order', 'merchant_name'];
            $options['attributesToHighlight'] = ['descriptions', 'product_codes'];
            $options['highlightPreTag'] = '<mark>';
            $options['highlightPostTag'] = '</mark>';

            return $meilisearch->search($searchQuery, $options);
        })->raw();

        return collect($results['hits'] ?? []);
    }

    /**
     * Check if a query string looks like a scanned barcode (UPC/EAN).
     */
    private function looksLikeBarcode(string $query): bool
    {
        $digits = preg_replace('/\D/', '', $query);

        return strlen($digits) >= 8 && strlen($digits) <= 14 && $digits === $query;
    }

    /**
     * Look up a UPC/EAN barcode via the UPCitemdb API.
     * Returns structured data with SKU and product title for searching.
     * Results are cached for 30 days to avoid hitting rate limits.
     *
     * If the API response doesn't contain a SKU, falls back to scraping
     * the upcitemdb.com product page which lists merchant-specific product
     * name variations that often include "Sku XXX-XXXX" patterns.
     *
     * @return array{sku: ?string, title: ?string, label: string}|null
     */
    private function lookupUpc(string $upc): ?array
    {
        return Cache::remember("upc_lookup_v3:{$upc}", now()->addDays(30), function () use ($upc) {
            try {
                $response = Http::timeout(5)
                    ->acceptJson()
                    ->get('https://api.upcitemdb.com/prod/trial/lookup', ['upc' => $upc]);

                if (! $response->successful()) {
                    return null;
                }

                $items = $response->json('items', []);

                if (empty($items)) {
                    return null;
                }

                $item = $items[0];
                $title = $item['title'] ?? $item['description'] ?? null;
                $model = $item['model'] ?? null;

                // Extract SKU from title/description (e.g., "Sku 639-8554" or "SKU: 639-8554")
                $sku = null;
                $searchText = ($title ?? '') . ' ' . ($item['description'] ?? '');
                if (preg_match('/\bsku[:\s#]*(\d[\d-]+\d)/i', $searchText, $m)) {
                    $sku = str_replace('-', '', $m[1]);
                } elseif ($model) {
                    $sku = str_replace('-', '', $model);
                }

                // If no SKU found from API, try scraping the website page
                // which lists product name variations with merchant SKUs
                if (! $sku) {
                    $sku = $this->scrapeUpcWebsiteSku($upc);
                }

                // Build display label
                $label = $title ?? 'Unknown product';
                if ($sku) {
                    $label .= " (SKU: {$sku})";
                }

                return [
                    'sku' => $sku,
                    'title' => $title,
                    'label' => $label,
                ];
            } catch (\Exception $e) {
                return null;
            }
        });
    }

    /**
     * Scrape the upcitemdb.com product page to extract SKU from product name variations.
     *
     * The website lists merchant-specific product names that often include
     * "Sku XXX-XXXX" patterns not available via the free API.
     */
    private function scrapeUpcWebsiteSku(string $upc): ?string
    {
        try {
            $response = Http::timeout(5)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0'])
                ->get("https://www.upcitemdb.com/upc/{$upc}");

            if (! $response->successful()) {
                return null;
            }

            $html = $response->body();

            // Look for SKU pattern anywhere in the page text
            // Matches "Sku 639-8554", "SKU: 123-4567", "Sku #639-8554", etc.
            if (preg_match('/\bsku[:\s#]*(\d[\d-]+\d)/i', $html, $m)) {
                return str_replace('-', '', $m[1]);
            }

            return null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get the receipt search expense IDs for filtering (called from buildFilterConditions).
     */
    private function getReceiptExpenseIds(): ?\Illuminate\Support\Collection
    {
        $this->executeReceiptSearch();

        return $this->receiptExpenseIds;
    }

    #[Title('Expenses')]
    public function render()
    {
        $this->authorize('viewAny', Expense::class);

        // Run receipt search BEFORE the view renders so $matchedReceiptItems
        // is populated before the template accesses it.
        $this->executeReceiptSearch();

        return view('livewire.expenses.index');
    }

    public function placeholder()
    {
        return view('livewire.expenses.expense-index-placeholder');
    }
}
