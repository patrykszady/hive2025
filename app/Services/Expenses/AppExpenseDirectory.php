<?php

namespace App\Services\Expenses;

use App\Http\Controllers\CompanyEmailController;
use App\Http\Controllers\ReceiptController;
use App\Models\Expense;
use App\Models\ExpenseSplits;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

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

    public function __construct(private ExpenseAccessGuard $guard)
    {
    }

    /**
     * @param  array{project?: ?string, vendor?: ?int, date_from?: ?string, date_to?: ?string, status?: list<string>, amount?: ?string}  $filters
     */
    public function query(User $user, array $filters): Builder
    {
        $query = Expense::query()
            ->with([
                'vendor:id,business_name,business_type',
                'project:id,project_name,address',
                'distribution:id,name',
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

        if (! empty($filters['status'])) {
            $this->applyStatusFilter($query, $filters['status']);
        }

        if (! empty($filters['amount'])) {
            $this->applyAmountFilter($query, (string) $filters['amount']);
        }

        return $query->orderByDesc('date')->orderByDesc('id');
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
     * the app's own form offers (no check/bank-account recording — a
     * desktop accounting feature the app doesn't surface): same ownership
     * whitelisting (ExpenseAccessGuard), same cross-vendor attach, same
     * optional receipt OCR.
     *
     * @param  array{amount: float|string, date: string, vendor_id: int, project_id?: ?int, note?: ?string, invoice?: ?string, paid_by?: ?int, reimbursement?: ?string, splits?: list<array{amount: float|string, project_id: int, note?: ?string, reimbursement?: ?string}>}  $data
     */
    public function create(User $user, array $data, ?UploadedFile $receipt): Expense
    {
        $hubVendorId = (int) $user->vendor->id;
        $isSplit = ! empty($data['splits']);
        $projectId = $isSplit ? null : (isset($data['project_id']) ? (int) $data['project_id'] : null);

        $this->guard->assertProjectAccessible($projectId, null);
        $ownerVendorId = $this->guard->resolveOwnerVendorId($hubVendorId, false, null);

        $expense = Expense::create([
            'amount' => $data['amount'],
            'date' => $data['date'],
            'invoice' => $data['invoice'] ?? null,
            'note' => $data['note'] ?? null,
            'project_id' => $projectId,
            'distribution_id' => null,
            'vendor_id' => $data['vendor_id'],
            'paid_by' => $data['paid_by'] ?? null,
            'reimbursment' => $data['reimbursement'] ?? null,
            'belongs_to_vendor_id' => $ownerVendorId,
            'created_by_user_id' => $user->id,
        ]);

        if ($isSplit) {
            $this->replaceSplits($expense, $data['splits']);
        }

        $this->guard->attachCrossVendorRelationships($expense);

        if ($receipt) {
            $this->attachReceiptFromUpload($expense, $receipt, false);
        }

        return $expense;
    }

    /**
     * Update an expense the way ExpenseForm::update() does, for the same
     * reduced field set create() uses. Mirrors the web exactly on one point
     * that looks surprising: created_by_user_id is overwritten to the
     * editing user on every save, not preserved from the original creator.
     *
     * @param  array{amount: float|string, date: string, vendor_id: int, project_id?: ?int, note?: ?string, invoice?: ?string, paid_by?: ?int, reimbursement?: ?string, splits?: list<array{amount: float|string, project_id: int, note?: ?string, reimbursement?: ?string}>}  $data
     */
    public function update(User $user, Expense $expense, array $data, ?UploadedFile $receipt): Expense
    {
        $hubVendorId = (int) $user->vendor->id;
        $isSplit = ! empty($data['splits']);
        $projectId = $isSplit ? null : (isset($data['project_id']) ? (int) $data['project_id'] : null);

        $this->guard->assertProjectAccessible($projectId, null);
        $ownerVendorId = $this->guard->resolveOwnerVendorId($hubVendorId, false, null);

        $expense->update([
            'amount' => $data['amount'],
            'date' => $data['date'],
            'invoice' => $data['invoice'] ?? null,
            'note' => $data['note'] ?? null,
            'project_id' => $projectId,
            'distribution_id' => null,
            'vendor_id' => $data['vendor_id'],
            'paid_by' => $data['paid_by'] ?? null,
            'reimbursment' => $data['reimbursement'] ?? null,
            'belongs_to_vendor_id' => $ownerVendorId,
            'created_by_user_id' => $user->id,
        ]);

        $this->replaceSplits($expense, $isSplit ? $data['splits'] : []);

        $this->guard->attachCrossVendorRelationships($expense);

        if ($receipt) {
            $this->attachReceiptFromUpload($expense, $receipt, false);
        }

        return $expense;
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
     * Replaces an expense's splits wholesale. The app's split editor has no
     * per-row ids to reconcile against (unlike the web's line-item-aware
     * ExpenseSplitsCreate), so every save simply deletes the old rows and
     * recreates them — simpler, and the end state (rows summing to the
     * expense amount, each on a tenant-visible project) is identical.
     *
     * @param  list<array{amount: float|string, project_id: int, note?: ?string, reimbursement?: ?string}>  $splits
     */
    private function replaceSplits(Expense $expense, array $splits): void
    {
        foreach ($expense->splits as $existing) {
            $existing->delete();
        }

        foreach ($splits as $split) {
            $projectId = (int) $split['project_id'];
            $this->guard->assertProjectAccessible($projectId, null);

            ExpenseSplits::create([
                'amount' => $split['amount'],
                'expense_id' => $expense->id,
                'project_id' => $projectId,
                'distribution_id' => null,
                'reimbursment' => $split['reimbursement'] ?? null,
                'note' => $split['note'] ?? null,
                'belongs_to_vendor_id' => $expense->belongs_to_vendor_id,
                'created_by_user_id' => auth()->id(),
            ]);
        }
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
