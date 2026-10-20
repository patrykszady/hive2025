<?php

namespace App\Services\Expenses;

use App\Http\Controllers\CompanyEmailController;
use App\Http\Controllers\ReceiptController;
use App\Jobs\FetchAmazonReceiptForExpense;
use App\Models\BankAccount;
use App\Models\Check;
use App\Models\CheckImage;
use App\Models\Distribution;
use App\Models\Expense;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Checks\CheckNumberSuggester;
use App\Services\Users\AppTeamDirectory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * The app Expenses tab's query building and writes, matching the web's
 * ExpenseIndex (filters this is worth having on a phone: project, vendor,
 * date range, status, amount) and ExpenseForm (create/update/receipt
 * upload). ExpenseScope (applied automatically on every Expense query)
 * already encodes the same visibility rule as ExpensePolicy — an Admin
 * sees the whole team's expenses, a Member only their own paid_by + any
 * via-vendor ones — so list filtering never needs to re-derive that here.
 *
 * Unlike AppProjectDirectory/AppProjectDirectory's cursor pagination, this
 * uses an offset LengthAwarePaginator: the Expenses screen needs a real
 * "N expenses" total the way the web's card does, which a cursor paginator
 * cannot cheaply provide.
 */
class AppExpenseDirectory
{
    /** Non-trashed statuses selectable from the app — mirrors ExpenseIndex::VALID_EXPENSE_STATUSES minus 'Deleted' (trash isn't exposed to the app). */
    private const VALID_STATUSES = ['Complete', 'No Transaction', 'No Project', 'Missing Info'];

    public function __construct(
        private ExpenseAccessGuard $guard,
        private AppTeamDirectory $teamDirectory,
        private UnmatchedTransactionFinder $unmatchedTransactions,
        private ExpenseSplitsWriter $splitsWriter,
    ) {
    }

    /**
     * The "Paid By" / "Reimbursement" pickers' employee options — the same
     * employed() members of the hub vendor ExpenseCreate::employees() lists.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, User>
     */
    public function employees(User $user): \Illuminate\Database\Eloquent\Collection
    {
        return $this->teamDirectory->teamMembers($user->vendor);
    }

    /**
     * The Reimbursement FILTER's "person" options — mirrors
     * ExpenseIndex::via_vendor_employees() exactly: narrower than
     * employees() above (the create form's "Paid By" list), this is only
     * team members brought on through a via-vendor relationship.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, User>
     */
    public function viaVendorEmployees(User $user): \Illuminate\Database\Eloquent\Collection
    {
        return $user->vendor->users()->employed()->wherePivotNotNull('via_vendor_id')->get();
    }

    /**
     * The "Reimbursement" picker's vendor options — mirrors
     * ExpenseCreate::reimbursment_vendors() exactly: Sub/1099 vendors that
     * can owe the company back for expenses paid on their behalf.
     *
     * @return \Illuminate\Support\Collection<int, Vendor>
     */
    public function reimbursementVendors(): \Illuminate\Support\Collection
    {
        return Vendor::whereIn('business_type', ['Sub', '1099'])
            ->orderBy('business_name')
            ->get(['id', 'business_name']);
    }

    /**
     * The "Attach to Existing Check" picker's options — mirrors
     * ExpenseCreate::available_checks() exactly: recent (60-day) checks,
     * newest first, capped at 50.
     *
     * @return list<array{id: int, label: string}>
     */
    public function checkOptions(): array
    {
        return Check::with('vendor', 'bank_account.bank')
            ->whereNull('deleted_at')
            ->where('date', '>=', now()->subDays(60))
            ->orderBy('date', 'DESC')
            ->limit(50)
            ->get()
            ->map(function (Check $check) {
                $label = "#{$check->id} - ".$check->check_type;
                if ($check->check_type === 'Check') {
                    $label .= " #{$check->check_number}";
                }
                $label .= ' - $'.number_format((float) $check->amount, 2);
                $label .= ' - '.$check->date->format('m/d/Y');
                if ($check->vendor) {
                    $label .= ' - '.$check->vendor->business_name;
                }

                return ['id' => $check->id, 'label' => $label];
            })
            ->values()
            ->all();
    }

    /**
     * The "Record a New Check" bank picker's options — mirrors
     * HandlesChecks::getBankAccountsProperty() exactly.
     *
     * @return \Illuminate\Support\Collection<int, BankAccount>
     */
    public function bankAccounts(): \Illuminate\Support\Collection
    {
        return BankAccount::latestCheckingAccounts()->get();
    }

    /**
     * @param  array{project?: ?string, vendor?: ?int, date_from?: ?string, date_to?: ?string, status?: list<string>, amount?: ?string, reimbursement?: ?string, direction?: ?string}  $filters
     */
    public function query(User $user, array $filters): Builder
    {
        $statuses = $filters['status'] ?? [];

        // Mirrors ExpenseIndex::isShowingTrashed(): 'Deleted' anywhere in
        // the selected statuses switches the base query to trashed-only
        // rows (other selected statuses, project/vendor/date/amount filters
        // etc. still narrow that set — see applyStatusFilter() below, which
        // already ignores 'Deleted' via VALID_STATUSES).
        $isShowingTrashed = in_array('Deleted', $statuses, true);

        $query = ($isShowingTrashed ? Expense::onlyTrashed() : Expense::query())
            ->with([
                'vendor:id,business_name,business_type',
                'project:id,project_name,address',
                'distribution:id,name',
                'splits.project:id,project_name',
                'splits.distribution:id,name',
            ]);

        $this->applyProjectFilter($query, $filters['project'] ?? null);

        if (! empty($filters['vendor']) && is_numeric($filters['vendor'])) {
            $query->where('vendor_id', (int) $filters['vendor']);
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('date', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('date', '<=', $filters['date_to']);
        }

        if (! empty($statuses)) {
            $this->applyStatusFilter($query, $statuses);
        }

        if (! empty($filters['amount'])) {
            $this->applyAmountFilter($query, (string) $filters['amount']);
        }

        if (! empty($filters['reimbursement'])) {
            $this->applyReimbursementFilter($query, (string) $filters['reimbursement']);
        }

        // Only the Date column is actually wired to wire:click="sort(...)"
        // on the web (resources/views/livewire/expenses/index.blade.php) —
        // Amount's header has no `sortable` prop — so that is the only
        // column the app offers a direction for too.
        $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        return $query->orderBy('date', $direction)->orderBy('id', $direction);
    }

    /**
     * Mirrors ExpenseIndex::buildFilterConditions()'s reimbursement
     * condition exactly: matches either the expense's own reimbursment
     * column or any of its splits' — the raw select value ('Client', an
     * employee's user id as a string, or 'V:{vendor_id}').
     */
    private function applyReimbursementFilter(Builder $query, string $value): void
    {
        $query->where(function (Builder $q) use ($value) {
            $q->where('reimbursment', $value)
                ->orWhereHas('splits', fn (Builder $s) => $s->where('reimbursment', $value));
        });
    }

    /**
     * The app's "Transactions" card under Expenses — mirrors
     * ExpenseIndex::transactions(): every bank row still unmatched (vendor
     * + date-range filters apply here too, matching the web's comment
     * "Only vendor & check filters are meaningful for transactions right
     * now" plus the date_range it also shares with the Expenses card; the
     * app has no check-embed view, so that filter doesn't apply here).
     * $filters['search'] is the card's own text box (vendor/description);
     * when it's blank, $filters['amount'] — the SAME input that filters
     * the Expenses card above it — filters this card too, exactly like the
     * web's `Transaction::scopedSearch($hasTextSearch ? $this->transaction_search : $this->amount, ...)`.
     *
     * @param  array{vendor?: ?int, date_from?: ?string, date_to?: ?string, search?: ?string, amount?: ?string}  $filters
     */
    public function unmatchedTransactionsQuery(User $user, array $filters): Builder
    {
        $query = $this->unmatchedTransactions->query((int) $user->vendor->id)
            ->with(['vendor:id,business_name', 'bank_account.bank']);

        if (! empty($filters['vendor']) && is_numeric($filters['vendor'])) {
            $query->where('vendor_id', (int) $filters['vendor']);
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('transaction_date', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('transaction_date', '<=', $filters['date_to']);
        }

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $query->where(function (Builder $q) use ($search) {
                $q->where('plaid_merchant_description', 'like', "%{$search}%")
                    ->orWhereHas('vendor', fn (Builder $v) => $v->where('business_name', 'like', "%{$search}%"));
            });
        } elseif (! empty($filters['amount'])) {
            $this->applyTransactionAmountFilter($query, (string) $filters['amount']);
        }

        return $query->orderByDesc('transaction_date')->orderByDesc('id');
    }

    /** A single still-unmatched transaction this user's vendor owns, or null (already claimed, or never theirs) — the "create an expense from this transaction" screen's existence check before it fetches the pre-fill. */
    public function unmatchedTransaction(User $user, int $transactionId): ?Transaction
    {
        return $this->unmatchedTransactions->find((int) $user->vendor->id, $transactionId);
    }

    /**
     * A single unmatched transaction, for the "create an expense from this
     * transaction" screen to pre-fill from — mirrors
     * ExpenseCreate::createExpenseFromTransaction()'s pre-fill (amount,
     * date, vendor, and the bank_account_id/check_type/check_number
     * fallback — see applyTransactionFallback()) plus the scanned check
     * image the web's expense form shows above the Project field
     * (resources/views/livewire/expenses/form.blade.php's
     * $transactionCheckImage block) when this charge (or its Check) has one.
     *
     * @return array{id: int, amount: float, date: ?string, vendor: ?array{id: int, name: string}, bank_account_id: ?int, check_type: ?string, check_number: ?string, payment_label: ?string, check_image: ?array{url: string, payee_name: ?string}}
     */
    public function transactionPrefill(Transaction $transaction): array
    {
        $checkType = $this->checkTypeForTransaction($transaction);

        $checkImage = CheckImage::query()
            ->where(function (Builder $q) use ($transaction) {
                $q->where('transaction_id', $transaction->id);
                if ($transaction->check_id) {
                    $q->orWhere('check_id', $transaction->check_id);
                }
            })
            ->first();

        return [
            'id' => $transaction->id,
            'amount' => (float) $transaction->amount,
            'date' => $transaction->transaction_date?->format('Y-m-d'),
            'vendor' => ($transaction->vendor && $transaction->vendor->id)
                ? ['id' => $transaction->vendor->id, 'name' => $transaction->vendor->business_name]
                : null,
            'bank_account_id' => $transaction->bank_account_id,
            'check_type' => $checkType,
            'check_number' => ($checkType === 'Check' && $transaction->check_number !== null) ? (string) $transaction->check_number : null,
            'payment_label' => $transaction->paymentLabel(),
            'check_image' => $checkImage ? [
                'url' => URL::temporarySignedRoute('checks.image.signed', now()->addHours(24), ['image' => $checkImage->id]),
                'payee_name' => $checkImage->resolved_payee_name,
            ] : null,
        ];
    }

    /**
     * Mirrors ExpenseCreate::createExpenseFromTransaction()'s check_number
     * → check_type mapping exactly: the two magic numbers Transaction uses
     * for a transfer/cash withdrawal, else a paper check.
     */
    private function checkTypeForTransaction(Transaction $transaction): ?string
    {
        if (! $transaction->check_number) {
            return null;
        }

        return match ((string) $transaction->check_number) {
            Transaction::TRANSFER_CHECK_NUMBER => 'Transfer',
            Transaction::CASH_CHECK_NUMBER => 'Cash',
            default => 'Check',
        };
    }

    /**
     * A simplified, app-sized version of the Transactions card's amount
     * search — same "exact two decimals, else starts-with-these-digits"
     * rule as applyAmountFilter() above, minus the splits join (a
     * Transaction has none).
     */
    private function applyTransactionAmountFilter(Builder $query, string $raw): void
    {
        $value = trim($raw);

        if ($value === '' || ! is_numeric($value)) {
            return;
        }

        if (preg_match('/^-?\d+\.\d{2}$/', $value) === 1) {
            $amount = round((float) $value, 2);
            $query->whereIn('amount', [abs($amount), -abs($amount)]);

            return;
        }

        $digits = rtrim(ltrim($value, '+-'), '.');
        if ($digits === '') {
            return;
        }

        $query->whereRaw('ABS(amount) LIKE ?', ["{$digits}%"]);
    }

    /**
     * Mirrors ExpenseIndex::buildFilterConditions()'s project_id handling:
     * a numeric id (direct or via a split), 'NO_PROJECT', 'SPLIT', or
     * 'D:{distribution id}'. Distributions aren't offered by the app's own
     * create form (a desktop-only bookkeeping bucket), but filtering by one
     * still works since expenses can carry one from the web.
     */
    private function applyProjectFilter(Builder $query, ?string $project): void
    {
        if ($project === null || $project === '') {
            return;
        }

        if (ctype_digit($project)) {
            $projectId = (int) $project;
            $query->where(function (Builder $q) use ($projectId) {
                $q->where('project_id', $projectId)
                    ->orWhereHas('splits', fn (Builder $s) => $s->where('project_id', $projectId));
            });

            return;
        }

        if ($project === 'NO_PROJECT') {
            $query->where(fn (Builder $q) => $q->whereNull('project_id')->orWhere('project_id', 0))
                ->whereNull('distribution_id')
                ->whereDoesntHave('splits');

            return;
        }

        if ($project === 'SPLIT') {
            $query->whereHas('splits');

            return;
        }

        if (str_starts_with($project, 'D:') && ctype_digit(substr($project, 2))) {
            $query->where('distribution_id', (int) substr($project, 2));
        }
    }

    /**
     * Status is computed (not indexed) here: Expense::calculateStatus()'s
     * truth table is "no project" crossed with "has a transaction" — the
     * same two conditions this expresses as Eloquent clauses, kept in sync
     * with that method's rules (private, so it can't be called directly;
     * $expense->status is still used read-only in ExpenseResource).
     *
     * @param  list<string>  $statuses
     */
    private function applyStatusFilter(Builder $query, array $statuses): void
    {
        $valid = array_values(array_intersect($statuses, self::VALID_STATUSES));

        if ($valid === []) {
            return;
        }

        $query->where(function (Builder $outer) use ($valid) {
            foreach ($valid as $status) {
                $outer->orWhere(fn (Builder $q) => $this->applySingleStatus($q, $status));
            }
        });
    }

    private function applySingleStatus(Builder $query, string $status): void
    {
        $wantsNoProject = in_array($status, ['Missing Info', 'No Project'], true);
        $wantsTransactions = in_array($status, ['No Project', 'Complete'], true);

        if ($wantsNoProject) {
            $query->where(fn (Builder $q) => $q->whereNull('project_id')->orWhere('project_id', 0))
                ->whereNull('distribution_id')
                ->whereDoesntHave('splits');
        } else {
            $query->where(function (Builder $q) {
                $q->where(fn (Builder $qq) => $qq->whereNotNull('project_id')->where('project_id', '!=', 0))
                    ->orWhereNotNull('distribution_id')
                    ->orWhereHas('splits');
            });
        }

        if ($wantsTransactions) {
            $query->where(function (Builder $q) {
                $q->whereHas('transactions')
                    ->orWhereHas('sharedTransactions')
                    ->orWhereHas('check', fn (Builder $c) => $c->whereHas('transactions'))
                    ->orWhereNotNull('paid_by');
            });
        } else {
            $query->whereDoesntHave('transactions')
                ->whereDoesntHave('sharedTransactions')
                ->whereDoesntHave('check', fn (Builder $c) => $c->whereHas('transactions'))
                ->whereNull('paid_by');
        }
    }

    /**
     * A simplified, app-sized version of ExpenseIndex's amount search: an
     * exact two-decimal value matches exactly (either sign); anything else
     * is a "starts with these digits" match on the unsigned amount — the
     * same "where it begins" idea as the web's amountPrefixRanges(), without
     * porting its full range table. ABS(amount) LIKE '<digits>%' works
     * unchanged on both MySQL (production) and SQLite (tests): both
     * stringify a numeric column for LIKE.
     */
    private function applyAmountFilter(Builder $query, string $raw): void
    {
        $value = trim($raw);

        if ($value === '' || ! is_numeric($value)) {
            return;
        }

        if (preg_match('/^-?\d+\.\d{2}$/', $value) === 1) {
            $amount = round((float) $value, 2);
            $pos = abs($amount);
            $neg = -$pos;

            $query->where(function (Builder $q) use ($pos, $neg) {
                $q->whereIn('amount', [$pos, $neg])
                    ->orWhereHas('splits', fn (Builder $s) => $s->whereIn('amount', [$pos, $neg]));
            });

            return;
        }

        $digits = rtrim(ltrim($value, '+-'), '.');
        if ($digits === '') {
            return;
        }

        $query->where(function (Builder $q) use ($digits) {
            $q->whereRaw('ABS(amount) LIKE ?', ["{$digits}%"])
                ->orWhereHas('splits', fn (Builder $s) => $s->whereRaw('ABS(amount) LIKE ?', ["{$digits}%"]));
        });
    }

    /**
     * Create an expense the way ExpenseForm::store() does for the fields
     * the app's own form offers: attach an already-existing check via
     * existing_check_id, OR record a brand-new one via bank_account_id/
     * check_type/check_number (mirrors ExpenseForm::store()'s own
     * find-or-create/dedupe), OR neither — same ownership whitelisting
     * (ExpenseAccessGuard), same cross-vendor attach, same optional receipt
     * OCR.
     *
     * @param  array{amount: float|string, date: string, vendor_id: int, project_id?: ?int, distribution_id?: ?int, note?: ?string, invoice?: ?string, paid_by?: ?int, reimbursement?: ?string, is_material_order?: bool, belongs_to_vendor_id?: ?int, existing_check_id?: ?int, bank_account_id?: ?int, check_type?: ?string, check_number?: ?int, transaction_id?: ?int, splits?: list<array{amount: float|string, project_id?: ?int, distribution_id?: ?int, note?: ?string, reimbursement?: ?string}>}  $data
     */
    public function create(User $user, array $data, ?UploadedFile $receipt): ExpenseSaveResult
    {
        $hubVendorId = (int) $user->vendor->id;
        $isSplit = ! empty($data['splits']);
        [$projectId, $distributionId] = $isSplit
            ? [null, null]
            : $this->guard->resolveProjectSelector($data['project_id'] ?? null, $data['distribution_id'] ?? null);
        $isMaterialOrder = (bool) ($data['is_material_order'] ?? false);
        $belongsToVendorId = isset($data['belongs_to_vendor_id']) && $data['belongs_to_vendor_id'] !== null
            ? (int) $data['belongs_to_vendor_id']
            : null;

        $this->guard->assertProjectAccessible($projectId, $distributionId);
        $ownerVendorId = $this->guard->resolveOwnerVendorId($hubVendorId, $isMaterialOrder, $belongsToVendorId);

        // "Create an expense from the transaction" (the Transactions card)
        // — mirrors ExpenseCreate::createExpenseFromTransaction(): the
        // transaction's own bank_account_id/check_number fill in whichever
        // of those fields the request didn't already send, same as the
        // web's $this->transaction? fallback in ExpenseForm::store().
        $transactionId = isset($data['transaction_id']) && $data['transaction_id'] !== null
            ? (int) $data['transaction_id']
            : null;
        $transaction = $this->guard->assertTransactionAccessible($transactionId, $hubVendorId);
        $data = $this->applyTransactionFallback($data, $transaction);

        // "Attach to Existing Check" — mirrors ExpenseForm::store()'s
        // existing_check_id handling: the expense simply points at an
        // already-existing check.
        $existingCheckId = isset($data['existing_check_id']) && $data['existing_check_id'] !== null
            ? (int) $data['existing_check_id']
            : null;
        $check = $this->guard->assertCheckAccessible($existingCheckId);

        // Recording a brand-new check — mirrors ExpenseForm::store()'s
        // $shouldCreateCheck branch: only when neither an existing check nor
        // "paid by an employee" already settled it.
        if (! $check && empty($data['paid_by'])) {
            $check = $this->createCheckForNewExpense($data, (int) $data['vendor_id'], $hubVendorId, (int) $user->id, $distributionId);
        }

        $expense = Expense::create([
            'amount' => $data['amount'],
            'date' => $data['date'],
            'invoice' => $data['invoice'] ?? null,
            'note' => $data['note'] ?? null,
            'project_id' => $projectId,
            'distribution_id' => $distributionId,
            'vendor_id' => $data['vendor_id'],
            'check_id' => $check?->id,
            'paid_by' => $data['paid_by'] ?? null,
            'reimbursment' => $data['reimbursement'] ?? null,
            'belongs_to_vendor_id' => $ownerVendorId,
            'created_by_user_id' => $user->id,
        ]);

        if ($check) {
            $check->recalculateAmount();
        }

        if ($isSplit) {
            $this->splitsWriter->sync($expense, $this->canonicalSplits($data['splits']), $hubVendorId, (int) $user->id);
        }

        $this->guard->attachCrossVendorRelationships($expense);

        if ($transaction) {
            $this->linkTransactionToExpense($transaction, $expense, $check, (int) $data['vendor_id'], $hubVendorId);
        }

        $receiptWarning = $receipt && ! $this->attachReceiptFromUpload($expense, $receipt, $isMaterialOrder)
            ? 'Expense saved but the receipt could not be processed. Please try uploading the receipt again.'
            : null;

        return new ExpenseSaveResult($expense, $receiptWarning);
    }

    /**
     * Maps the app's own split shape (project_id XOR distribution_id, no
     * per-split receipt line items) into ExpenseSplitsWriter::sync()'s
     * canonical shape — see that class's docblock.
     *
     * @param  list<array{id?: ?int, amount: float|string, project_id?: ?int, distribution_id?: ?int, note?: ?string, reimbursement?: ?string}>  $splits
     * @return list<array{id: ?int, amount: float|string, project_id: ?int, distribution_id: ?int, reimbursement: ?string, note: ?string}>
     */
    private function canonicalSplits(array $splits): array
    {
        return array_map(fn (array $split) => [
            'id' => isset($split['id']) ? (int) $split['id'] : null,
            'amount' => $split['amount'],
            'project_id' => isset($split['project_id']) ? (int) $split['project_id'] : null,
            'distribution_id' => isset($split['distribution_id']) ? (int) $split['distribution_id'] : null,
            'reimbursement' => $split['reimbursement'] ?? null,
            'note' => $split['note'] ?? null,
            // No 'items' key at all — the app never sends per-split receipt
            // line items, so ExpenseSplitsWriter preserves whatever an
            // existing split already has instead of clearing it.
        ], $splits);
    }

    /**
     * Fills in bank_account_id/check_type/check_number from the transaction
     * when the request didn't already send them — mirrors
     * ExpenseForm::store()'s "$this->transaction?->" fallback exactly
     * (lines handling bank_account_id/check_type/check_number before the
     * check-creation branch runs), so createCheckForNewExpense() below sees
     * the same effective values the web's $shouldCreateCheck branch would.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function applyTransactionFallback(array $data, ?Transaction $transaction): array
    {
        if (! $transaction) {
            return $data;
        }

        if (empty($data['bank_account_id']) && $transaction->bank_account_id) {
            $data['bank_account_id'] = $transaction->bank_account_id;
        }

        if (empty($data['check_type'])) {
            $checkType = $this->checkTypeForTransaction($transaction);
            if ($checkType) {
                $data['check_type'] = $checkType;
            }
        }

        if (
            ($data['check_type'] ?? null) === 'Check'
            && empty($data['check_number'])
            && $transaction->check_number
            && ! in_array((string) $transaction->check_number, [Transaction::TRANSFER_CHECK_NUMBER, Transaction::CASH_CHECK_NUMBER], true)
        ) {
            $data['check_number'] = $transaction->check_number;
        }

        return $data;
    }

    /**
     * Links the source transaction to the just-created expense — mirrors
     * ExpenseForm::store()'s `if ($this->transaction) { ... }` block
     * exactly: prefer pointing the transaction at the check (never both a
     * check AND a direct expense link), fall back to a direct expense link
     * with manualExpenseLink set (a person chose this, not automation), and
     * update the transaction's own vendor_id when the expense's vendor
     * differs from the acting user's hub vendor.
     */
    private function linkTransactionToExpense(Transaction $transaction, Expense $expense, ?Check $check, int $vendorId, int $hubVendorId): void
    {
        $finalCheckId = $check?->id ?? $transaction->check_id;

        if ($finalCheckId) {
            $transaction->check_id = $finalCheckId;
            $transaction->expense_id = null;
        } else {
            $transaction->expense_id = $expense->id;
            $transaction->manualExpenseLink = true;
        }

        if ($vendorId !== $hubVendorId) {
            $transaction->vendor_id = $vendorId;
        }

        $transaction->save();
    }

    /**
     * Update an expense the way ExpenseForm::update() does, for the same
     * reduced field set create() uses. Mirrors the web exactly on one point
     * that looks surprising: created_by_user_id is overwritten to the
     * editing user on every save, not preserved from the original creator.
     *
     * @param  array{amount: float|string, date: string, vendor_id: int, project_id?: ?int, distribution_id?: ?int, note?: ?string, invoice?: ?string, paid_by?: ?int, reimbursement?: ?string, is_material_order?: bool, belongs_to_vendor_id?: ?int, bank_account_id?: ?int, check_type?: ?string, check_number?: ?int, splits?: list<array{amount: float|string, project_id?: ?int, distribution_id?: ?int, note?: ?string, reimbursement?: ?string}>}  $data
     */
    public function update(User $user, Expense $expense, array $data, ?UploadedFile $receipt): ExpenseSaveResult
    {
        $hubVendorId = (int) $user->vendor->id;
        $isSplit = ! empty($data['splits']);
        [$projectId, $distributionId] = $isSplit
            ? [null, null]
            : $this->guard->resolveProjectSelector($data['project_id'] ?? null, $data['distribution_id'] ?? null);
        $isMaterialOrder = (bool) ($data['is_material_order'] ?? false);
        $belongsToVendorId = isset($data['belongs_to_vendor_id']) && $data['belongs_to_vendor_id'] !== null
            ? (int) $data['belongs_to_vendor_id']
            : null;

        $this->guard->assertProjectAccessible($projectId, $distributionId);
        $ownerVendorId = $this->guard->resolveOwnerVendorId($hubVendorId, $isMaterialOrder, $belongsToVendorId);

        $expense->update([
            'amount' => $data['amount'],
            'date' => $data['date'],
            'invoice' => $data['invoice'] ?? null,
            'note' => $data['note'] ?? null,
            'project_id' => $projectId,
            'distribution_id' => $distributionId,
            'vendor_id' => $data['vendor_id'],
            'paid_by' => $data['paid_by'] ?? null,
            'reimbursment' => $data['reimbursement'] ?? null,
            'belongs_to_vendor_id' => $ownerVendorId,
            'created_by_user_id' => $user->id,
        ]);

        // Recording/updating/detaching the linked check — mirrors
        // ExpenseForm::update()'s "Handle existing check" block exactly.
        $this->resolveCheckOnUpdate($expense, $data, (int) $data['vendor_id'], $hubVendorId, (int) $user->id, $distributionId);

        $this->splitsWriter->sync($expense, $isSplit ? $this->canonicalSplits($data['splits']) : [], $hubVendorId, (int) $user->id);

        $this->guard->attachCrossVendorRelationships($expense);

        $receiptWarning = $receipt && ! $this->attachReceiptFromUpload($expense, $receipt, $isMaterialOrder)
            ? 'Expense updated but the receipt could not be processed. Please try uploading the receipt again.'
            : null;

        return new ExpenseSaveResult($expense, $receiptWarning);
    }

    /**
     * Finds a matching already-recorded paper check (same bank/type/number/
     * vendor) or creates a new one — mirrors ExpenseForm::store()'s
     * check-creation branch. Returns null when bank_account_id/check_type
     * weren't both submitted (or the bank account doesn't exist).
     *
     * @param  array{date: string, amount: float|string, bank_account_id?: ?int, check_type?: ?string, check_number?: ?int}  $data
     */
    private function createCheckForNewExpense(array $data, int $vendorId, int $actingVendorId, int $actingUserId, ?int $distributionId = null): ?Check
    {
        $bankAccountId = isset($data['bank_account_id']) && $data['bank_account_id'] !== null ? (int) $data['bank_account_id'] : null;
        $checkType = $data['check_type'] ?? null;
        $checkNumber = isset($data['check_number']) && $data['check_number'] !== null ? (int) $data['check_number'] : null;

        if (! $bankAccountId || ! $checkType || ! BankAccount::whereKey($bankAccountId)->exists()) {
            return null;
        }

        if ($checkType === 'Check' && $checkNumber !== null) {
            $existing = Check::whereNull('deleted_at')
                ->where('bank_account_id', $bankAccountId)
                ->where('check_type', $checkType)
                ->where('check_number', $checkNumber)
                ->where('vendor_id', $vendorId)
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        return Check::create([
            'check_type' => $checkType,
            'check_number' => $checkNumber,
            'date' => $data['date'],
            'bank_account_id' => $bankAccountId,
            'amount' => $data['amount'],
            // A distribution's own user — mirrors ExpenseForm::store()'s
            // $dist_user (ExpenseForm.php:508-514): only a brand-new check
            // ever gets this; an existing/reused one (above) is untouched.
            'user_id' => $this->distributionUserId($distributionId),
            'vendor_id' => $vendorId,
            'belongs_to_vendor_id' => $actingVendorId,
            'created_by_user_id' => $actingUserId,
        ]);
    }

    /**
     * Mirrors ExpenseForm::store()/update()'s $dist_user calculation exactly
     * (ExpenseForm.php:381-387,508-514): a distribution's own user_id of 0
     * is treated the same as having none.
     */
    private function distributionUserId(?int $distributionId): ?int
    {
        if (! $distributionId) {
            return null;
        }

        $userId = Distribution::findOrFail($distributionId)->user_id;

        return $userId != 0 ? $userId : null;
    }

    /**
     * Creates/reuses/detaches $expense's check on an edit — mirrors
     * ExpenseForm::update()'s "Handle existing check" block exactly: when
     * bank_account_id/check_type are (still) submitted and not paid_by, find
     * or create the matching check and point the expense at it (recalculating
     * the OLD check first if it's being swapped out); otherwise, if the
     * expense had a check and the bank/check fields were cleared, detach it.
     *
     * @param  array{date: string, amount: float|string, paid_by?: ?int, bank_account_id?: ?int, check_type?: ?string, check_number?: ?int}  $data
     */
    private function resolveCheckOnUpdate(Expense $expense, array $data, int $vendorId, int $actingVendorId, int $actingUserId, ?int $distributionId = null): void
    {
        $check = $expense->check;
        $bankAccountId = isset($data['bank_account_id']) && $data['bank_account_id'] !== null ? (int) $data['bank_account_id'] : null;
        $checkType = $data['check_type'] ?? null;
        $checkNumber = isset($data['check_number']) && $data['check_number'] !== null ? (int) $data['check_number'] : null;

        if (empty($data['paid_by']) && $bankAccountId && $checkType && BankAccount::whereKey($bankAccountId)->exists()) {
            $existing = null;
            if ($checkType === 'Check' && $checkNumber !== null) {
                $existing = Check::whereNull('deleted_at')
                    ->where('bank_account_id', $bankAccountId)
                    ->where('check_type', $checkType)
                    ->where('check_number', $checkNumber)
                    ->where('vendor_id', $vendorId)
                    ->first();
            }

            if ($existing) {
                if ($check && $check->id !== $existing->id) {
                    $check->recalculateAmount();
                }
                $check = $existing;
            } elseif (! $check || (int) $check->check_number !== $checkNumber || (int) $check->bank_account_id !== $bankAccountId) {
                $check = Check::create([
                    'check_type' => $checkType,
                    'check_number' => $checkNumber,
                    'date' => $data['date'],
                    'bank_account_id' => $bankAccountId,
                    'amount' => $data['amount'],
                    // See createCheckForNewExpense()'s matching comment.
                    'user_id' => $this->distributionUserId($distributionId),
                    'vendor_id' => $vendorId,
                    'belongs_to_vendor_id' => $actingVendorId,
                    'created_by_user_id' => $actingUserId,
                ]);
            }

            $expense->update(['check_id' => $check->id]);
            $check->recalculateAmount();
        } elseif ($check) {
            $expense->update(['check_id' => null]);
            $check->recalculateAmount();
        }
    }

    /**
     * The same delete rule ExpenseCreate::remove()/ExpenseIndex::bulkDelete()
     * apply (not ExpensePolicy::delete(), which the web never actually
     * calls for this): an admin may always delete; a member only when
     * nothing has matched to it yet.
     */
    public function canDelete(User $user, Expense $expense): bool
    {
        if ($user->vendor_role === 'Admin') {
            return true;
        }

        return ! $expense->transactions()->exists();
    }

    /**
     * Bulk-select "Delete selected" — mirrors ExpenseIndex::bulkDelete()
     * exactly: the same per-row guard as canDelete() above (a non-admin
     * skips any selected expense that already has a matched transaction
     * rather than failing the whole batch), via the same
     * deleteWithAssociations() the single-delete endpoint uses. $ids is
     * trusted no further than Expense::whereIn() already scopes it — the
     * caller only ever selects from rows ExpenseScope already let them see,
     * same as the web.
     *
     * @param  list<int>  $ids
     * @return array{deleted: list<int>, skipped: int}
     */
    public function bulkDelete(User $user, array $ids): array
    {
        if ($ids === []) {
            return ['deleted' => [], 'skipped' => 0];
        }

        $isAdmin = $user->vendor_role === 'Admin';

        $expenses = Expense::whereIn('id', $ids)
            ->with(['transactions', 'splits', 'receipts'])
            ->get();

        $deleted = [];
        $skipped = 0;

        foreach ($expenses as $expense) {
            if (! $isAdmin && $expense->transactions->isNotEmpty()) {
                $skipped++;

                continue;
            }

            $expense->deleteWithAssociations();
            $deleted[] = $expense->id;
        }

        return ['deleted' => $deleted, 'skipped' => $skipped];
    }

    /**
     * Mirrors ExpenseIndex::restoreExpense() exactly, including the Amazon
     * receipt re-fetch for vendor 54 when the restored expense has none yet.
     * The controller resolves/authorizes $expense via Expense::onlyTrashed()
     * before calling this, the same ordering restoreExpense() itself has
     * (route-model-bound trashed lookup, then authorize('restore', ...)).
     */
    public function restore(Expense $expense): Expense
    {
        $expense->restore();

        if ((int) $expense->vendor_id === 54 && ! $expense->receipts()->exists()) {
            FetchAmazonReceiptForExpense::dispatch($expense);
        }

        return $expense->refresh();
    }

    /**
     * Mirrors ExpenseForm::upload_receipt_file() exactly: same temp-file
     * handling, same OCR call (ReceiptController::extractReceipt), and the
     * same save (CompanyEmailController::saveExpenseReceipt) — a receipt
     * saved through the app produces the same ExpenseReceipts row, OCR line
     * items, and downstream payment-sync side effects (ExpensePaymentSync,
     * wired through ExpenseReceipts::booted()) as one saved through the web.
     */
    public function attachReceiptFromUpload(Expense $expense, UploadedFile $file, bool $isMaterialOrder): bool
    {
        $docType = $file->getClientOriginalExtension();
        $ocrFilename = date('Y-m-d-H-i-s').'-'.rand(10, 99).'.'.$docType;
        $ocrPath = '_temp_ocr/'.$ocrFilename;

        Storage::disk('files')->put($ocrPath, file_get_contents($file->getRealPath()));

        $analyzerId = $isMaterialOrder ? config('services.azure_cu.analyzer_id_material_order') : null;
        $effectiveDocType = $isMaterialOrder ? 'material_order' : $docType;

        $ocrData = app(ReceiptController::class)->extractReceipt($ocrPath, $effectiveDocType, (float) $expense->amount, null, null, $analyzerId);

        if (isset($ocrData['error']) && $ocrData['error'] === true) {
            Storage::disk('files')->delete($ocrPath);

            return false;
        }

        app(CompanyEmailController::class)->saveExpenseReceipt($expense->id, $ocrData, $ocrFilename, null, false, $isMaterialOrder);

        Storage::disk('files')->delete($ocrPath);

        return true;
    }
}
