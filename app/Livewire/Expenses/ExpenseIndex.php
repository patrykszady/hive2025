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
use Flux\Flux;
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

    /**
     * How many skeleton rows the loading placeholder should paint — the card's
     * page size, so the skeleton is the same height as the table that replaces
     * it (no jump on load). Callers that can cheaply COUNT the real rows pass
     * the smaller of the two.
     */
    public static function placeholderRows(?string $view = null): int
    {
        return $view === null ? 8 : 5;
    }

    /**
     * Column defs for the expenses table — the loading skeleton renders from
     * the same array as the real header row, so widths can never drift apart.
     * Columns vary by view, so the caller passes the same $view the table uses.
     *
     * @return array<int, array{label: string, width: string, skeleton?: string, skeletonWidth?: string}>
     */
    public static function columnDefs(?string $view = null): array
    {
        $isEmbedView = in_array($view, ['checks.show', 'vendors.show'], true);

        $columns = [
            ['label' => 'Amount', 'width' => $isEmbedView ? 'w-[20%] min-w-[4.5rem]' : 'w-[14%] min-w-[5.5rem]', 'skeletonWidth' => 'w-16'],
            ['label' => 'Date', 'width' => $isEmbedView ? 'w-[18%] min-w-[4.5rem]' : 'w-[14%] min-w-[6rem]', 'skeletonWidth' => 'w-16'],
        ];

        if (! $isEmbedView) {
            $columns[] = ['label' => 'Vendor', 'width' => 'w-[25%] min-w-0', 'skeletonWidth' => 'w-28'];
        }

        if ($view !== 'projects.show') {
            $columns[] = ['label' => 'Project', 'width' => ($isEmbedView ? 'w-[37%]' : 'w-[30%]').' min-w-0', 'skeletonWidth' => 'w-32'];
        }

        $columns[] = ['label' => 'Status', 'width' => ($isEmbedView ? 'w-[25%] min-w-[4.5rem]' : 'w-[17%] min-w-[5rem]').' shrink-0', 'skeleton' => 'badge'];

        return $columns;
    }

    /**
     * Column defs for the unmatched-Transactions card on /expenses — its
     * loading skeleton renders from the same array as the real header row.
     *
     * @return array<int, array{label: string, width: string, skeleton?: string, skeletonWidth?: string}>
     */
    public static function transactionColumnDefs(): array
    {
        return [
            ['label' => 'Amount', 'width' => 'w-[16%]', 'skeletonWidth' => 'w-16'],
            ['label' => 'Date', 'width' => 'w-[16%]', 'skeletonWidth' => 'w-16'],
            ['label' => 'Vendor', 'width' => 'w-[30%] min-w-0', 'skeletonWidth' => 'w-32'],
            ['label' => 'Bank', 'width' => 'w-[20%] min-w-0', 'skeletonWidth' => 'w-24'],
            ['label' => 'Account', 'width' => 'w-[18%] min-w-0', 'skeletonWidth' => 'w-20'],
        ];
    }

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
    public string $transaction_search = '';

    #[Url(except: '')]
    public $reimbursement_filter = '';

    public ?DateRange $date_range = null;

    public $check = '';
    public $bank_plaid_ins_id = '';
    public $banks = [];
    public $distributions = [];

    /**
     * Search terms for the vendor/project filters. The option lists used to be
     * public properties holding every vendor (831) and project (413) — that is
     * ~1.1k <ui-option> elements rendered server-side AND dehydrated into the
     * wire payload on every request. They are computed + search-limited now,
     * backed by Meilisearch (both models are Scout Searchable).
     */
    public string $vendorSearch = '';

    public string $projectSearch = '';

    /** Grows as the user scrolls the open dropdown (wire:intersect sentinel). */
    public int $vendorLimit = self::FILTER_OPTION_LIMIT;

    public int $projectLimit = self::FILTER_OPTION_LIMIT;

    /** Typing a new term restarts paging from the top. */
    public function updatedVendorSearch(): void
    {
        $this->vendorLimit = self::FILTER_OPTION_LIMIT;
    }

    public function updatedProjectSearch(): void
    {
        $this->projectLimit = self::FILTER_OPTION_LIMIT;
    }

    public function loadMoreVendorOptions(): void
    {
        $this->vendorLimit += self::FILTER_OPTION_LIMIT;
    }

    public function loadMoreProjectOptions(): void
    {
        $this->projectLimit += self::FILTER_OPTION_LIMIT;
    }

    /** True while more rows exist beyond the current limit. */
    public function hasMoreVendorOptions(): bool
    {
        return count($this->vendorOptions) >= $this->vendorLimit;
    }

    public function hasMoreProjectOptions(): bool
    {
        return count($this->projectOptions) >= $this->projectLimit;
    }
    public $bank_account_ids = [];
    public $view = null;
    public $paginate_number = 8;
    public $sortBy = 'date';
    public $sortDirection = 'desc';
    public array $removedTransactionIds = [];
    public array $matchedReceiptItems = [];
    public string $upcProductName = '';

    /** @var array<int> Selected expense IDs for bulk actions. */
    public array $selected = [];

    /** @var array<int> Recently deleted expense IDs to hide while Meilisearch reindexes. */
    public array $recentlyDeletedExpenseIds = [];

    protected $listeners = ['refreshComponent' => '$refresh'];

    public function updating()
    {
        $this->resetPage('expenses-page');
        $this->resetPage('transactions-page');
        $this->recentlyDeletedExpenseIds = [];
    }

    public function clearSelection(): void
    {
        $this->selected = [];
    }

    public function updatedPaginators($page, $pageName): void
    {
        // Selections are scoped to the current page; clear them on page change.
        $this->selected = [];
    }

    public function bulkDelete(): void
    {
        if (empty($this->selected)) {
            return;
        }

        $isAdmin = auth()->user()?->vendor_role === 'Admin';

        // Load what the guard and cascadeDeleteExpense() both need — the loop
        // used to fire a fresh exists() plus lazy loads per selected expense.
        $expenses = Expense::whereIn('id', $this->selected)
            ->with(['transactions', 'splits', 'receipts'])
            ->get();
        $deletedIds = [];
        $skipped = 0;

        foreach ($expenses as $expense) {
            // Mirror single-delete rule from ExpenseCreate::remove():
            // non-admins cannot delete an expense that has matched transactions.
            if (! $isAdmin && $expense->transactions->isNotEmpty()) {
                $skipped++;
                continue;
            }

            $this->cascadeDeleteExpense($expense);
            $deletedIds[] = $expense->id;
        }

        $this->selected = [];

        // Hide deleted rows immediately even if Meilisearch hasn't reindexed yet.
        $this->recentlyDeletedExpenseIds = array_values(array_unique(
            array_merge($this->recentlyDeletedExpenseIds, $deletedIds)
        ));

        unset($this->expenses);

        $count = count($deletedIds);
        $heading = $count === 1 ? '1 expense deleted.' : "{$count} expenses deleted.";
        $text = $skipped > 0 ? "{$skipped} skipped (admin-only: has transactions)." : '';
        Flux::toast($text, $heading, 5000, 'success', 'top right');
    }

    /**
     * Cascade-delete an expense the same way ExpenseForm::delete() does:
     * detach associations, soft-delete splits/receipts, null transactions, drop check pivots.
     */
    private function cascadeDeleteExpense(Expense $expense): void
    {
        foreach ($expense->associated as $child) {
            $child->parent_expense_id = null;
            $child->save();
        }

        foreach ($expense->splits as $split) {
            $split->delete();
        }

        foreach ($expense->transactions as $transaction) {
            $transaction->expense_id = null;
            $transaction->vendor_id = null;
            $transaction->save();
        }

        foreach ($expense->receipts as $receipt) {
            $receipt->delete();
        }

        $expense->checks()->detach();
        if ($expense->check_id) {
            $expense->check_id = null;
            $expense->save();
        } else {
            $expense->searchable();
        }

        $expense->delete();
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
            $expense->searchable();
        }

        $check = Check::find($this->check);
        if ($check) {
            // Reimbursement-type expenses count as deductions, so the shared
            // recalculation must be used here too (not a plain sum).
            $check->recalculateAmount();
        }

        $this->dispatch('refreshComponent')->to('checks.check-show');
    }

    public function mount()
    {
        if (! is_null($this->view)) {
            $this->paginate_number = 5;
        }

        $vendorId = auth()->user()->vendor->id;

        $this->distributions = Cache::remember("filters:v{$vendorId}:distributions", 600, function () {
            return Distribution::all(['id', 'name']);
        });
    }

    /** How many options a filter dropdown renders at once. */
    private const FILTER_OPTION_LIMIT = 25;

    /**
     * Vendors for the filter dropdown: the search hits when the user types,
     * otherwise the most-used ones — plus whatever is currently selected, so
     * an active filter always shows its own label.
     */
    #[Computed]
    public function vendorOptions()
    {
        $term = trim($this->vendorSearch);
        $columns = ['id', 'business_name', 'business_type'];

        // Embedded on a project page: only vendors billed to that project.
        if ($this->view === 'projects.show' && $this->project_id) {
            return Vendor::whereHas('expenses', fn ($q) => $q->where('project_id', $this->project_id))
                ->when($term !== '', fn ($q) => $q->where('business_name', 'like', "%{$term}%"))
                ->orderBy('business_name')
                ->limit($this->vendorLimit)
                ->get($columns);
        }

        $options = $term !== ''
            ? Vendor::search($term)->take($this->vendorLimit)->get()
            : Vendor::whereHas('expenses')
                ->orWhereHas('transactions')
                ->orderBy('business_name')
                ->limit($this->vendorLimit)
                ->get($columns);

        return $this->withSelectedOption($options, $this->expense_vendor, Vendor::class);
    }

    /**
     * Projects for the filter dropdown — same shape as vendorOptions().
     */
    #[Computed]
    public function projectOptions()
    {
        $term = trim($this->projectSearch);
        $columns = ['id', 'project_name', 'address'];

        // Embedded on a vendor page: only projects that vendor billed to.
        if ($this->view === 'vendors.show' && $this->expense_vendor) {
            return Project::whereHas('expenses', fn ($q) => $q->where('vendor_id', $this->expense_vendor))
                ->when($term !== '', fn ($q) => $q->where('project_name', 'like', "%{$term}%"))
                ->orderByDesc('created_at')
                ->limit($this->projectLimit)
                ->get($columns);
        }

        $options = $term !== ''
            ? Project::search($term)->take($this->projectLimit)->get()
            : Project::whereHas('expenses')
                ->orderByDesc('created_at')
                ->limit($this->projectLimit)
                ->get($columns);

        return $this->withSelectedOption($options, $this->project_id, Project::class);
    }

    /**
     * Keep the active filter's own option in the list. Without this a selected
     * value outside the current page of results renders as a blank control.
     */
    private function withSelectedOption($options, $selectedId, string $model)
    {
        $selectedId = is_string($selectedId) || is_int($selectedId) ? trim((string) $selectedId) : '';

        // '0', 'NO_PROJECT', 'SPLIT', 'D:3' are static options in the blade.
        if ($selectedId === '' || ! ctype_digit($selectedId) || (int) $selectedId === 0) {
            return $options;
        }

        if ($options->contains('id', (int) $selectedId)) {
            return $options;
        }

        $selected = $model::find((int) $selectedId);

        return $selected ? $options->prepend($selected) : $options;
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
        // All amount handling (parent + split, exact + prefix, positive + negative)
        // is now expressed as Meilisearch filter conditions in buildFilterConditions().
        // This collapses what used to be a multi-query merge-in-PHP path into a
        // single Meilisearch round trip with native pagination.
        $expenses = Expense::scopedSearch(
            '',
            $this->buildFilterConditions(),
            $this->sortBy,
            $this->sortDirection,
            null,
            $this->isShowingTrashed()
        )->paginateWithSearchData($this->paginate_number, pageName: 'expenses-page');

        // If the requested page is past the last page (e.g. deep-linked URL or
        // filters narrowed results), bounce back to page 1 instead of showing
        // an empty table. Strip the expenses-page query string from the URL
        // so the address bar reflects page 1.
        if ($expenses->isEmpty() && $expenses->currentPage() > 1) {
            $this->resetPage('expenses-page');

            $cleanQuery = collect(request()->query())
                ->except('expenses-page')
                ->toArray();
            $cleanUrl = url()->current() . (empty($cleanQuery) ? '' : '?' . http_build_query($cleanQuery));
            $this->js("window.history.replaceState({}, '', " . json_encode($cleanUrl) . ")");

            return $this->expenses();
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
        )->paginate(
            // 8, matching the Expenses card: inserting a 100-row subtree froze
            // the page on load — DOM build + style/layout on the main thread.
            // The card paginates, so the rest is a click away.
            8,
            pageName: 'transactions-page'
        );

        // Filter out transactions that were just converted (MeiliSearch may not have indexed yet)
        if (!empty($this->removedTransactionIds)) {
            $removed = $this->removedTransactionIds;
            $beforeCount = $transactions->getCollection()->count();
            $transactions->setCollection(
                $transactions->getCollection()->reject(fn ($t) => in_array($t->id, $removed))
            );

            // setCollection() doesn't touch total(), so the "N unmatched"
            // badge would hold the stale MeiliSearch count until re-index.
            // Subtract only what was rejected THIS render: once MeiliSearch
            // catches up the id stops appearing and the native total is
            // already correct — no double-counting.
            $rejectedNow = $beforeCount - $transactions->getCollection()->count();
            if ($rejectedNow > 0) {
                $transactions = new LengthAwarePaginator(
                    $transactions->getCollection(),
                    max(0, $transactions->total() - $rejectedNow),
                    $transactions->perPage(),
                    $transactions->currentPage(),
                    [
                        'path' => LengthAwarePaginator::resolveCurrentPath(),
                        'pageName' => 'transactions-page',
                    ],
                );
            }
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
        
        // Apply check filter if present (covers both direct check_id and many-to-many pivot)
        if (is_numeric($this->check)) {
            $filterConditions[] = "check_ids = {$this->check}";

            // Vendor reimbursements deducted by this check render in their own
            // card on checks.show — keep them out of the main expense list.
            $checkVendorId = Check::withoutGlobalScopes()->find($this->check)?->vendor_id;
            if ($checkVendorId) {
                $filterConditions[] = "NOT reimbursment = 'V:{$checkVendorId}'";
            }
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

        // Apply amount filter (parent OR any split) entirely via Meilisearch.
        // Both `amount` and `split_amounts` are filterable; ranges work on numeric arrays.
        $amountFilter = $this->buildAmountFilter();
        if ($amountFilter !== null) {
            $filterConditions[] = $amountFilter;
        }

        // Hide rows that were just bulk-deleted (Meilisearch may lag behind the DB).
        if (! empty($this->recentlyDeletedExpenseIds)) {
            $excludeList = implode(', ', array_map('intval', $this->recentlyDeletedExpenseIds));
            $filterConditions[] = "NOT id IN [{$excludeList}]";
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
     * Build a Meilisearch amount filter that matches an expense whose own
     * amount OR any of its split amounts matches the user input. Mirrors the
     * positive value into the negative range so refunds/credits surface too.
     *
     * Returns null when amount input is empty or non-numeric.
     */
    private function buildAmountFilter(): ?string
    {
        if (! $this->isNumericAmountSearch()) {
            return null;
        }

        $mode = $this->amountSearchMode();

        if ($mode === 'exact') {
            $value = round((float) $this->normalizedAmountInput(), 2);
            $pos = abs($value);
            $neg = -$pos;

            return "(amount = {$pos} OR amount = {$neg} OR split_amounts = {$pos} OR split_amounts = {$neg})";
        }

        if ($mode === 'prefix') {
            [$min, $upperExclusive] = $this->amountPrefixBounds();
            $negMin = -$upperExclusive;
            $negMax = -$min;

            return '('
                . "(amount >= {$min} AND amount < {$upperExclusive})"
                . " OR (amount > {$negMin} AND amount <= {$negMax})"
                . " OR (split_amounts >= {$min} AND split_amounts < {$upperExclusive})"
                . " OR (split_amounts > {$negMin} AND split_amounts <= {$negMax})"
                . ')';
        }

        return null;
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

    public function placeholder(array $params = [])
    {
        // Livewire hands mount params to placeholder() before mount(), so the
        // public props still hold their defaults here.
        $view = $params['view'] ?? null;
        $projectId = $params['project_id'] ?? $params['projectId'] ?? null;

        // Cheap COUNT capped at the card's page size, so the skeleton paints
        // what will actually arrive instead of a fixed number.
        $rows = is_numeric($projectId)
            ? min(
                Expense::withoutGlobalScope(ExpenseScope::class)->where('project_id', (int) $projectId)->count(),
                static::placeholderRows($view)
            )
            : static::placeholderRows($view);

        return view('livewire.expenses.expense-index-placeholder', [
            'tableView' => $view,
            'rows' => $rows,
        ]);
    }
}
