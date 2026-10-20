<?php

namespace App\Services\Expenses;

use App\Models\Expense;
use App\Models\ExpenseSplits;

/**
 * Synchronizes an expense's splits to exactly the rows handed in — the
 * logic App\Livewire\Forms\ExpenseForm::save_splits() (:228-284) used to
 * inline for the web, extracted so App\Services\Expenses\
 * AppExpenseDirectory's app API write path enforces the exact same rules:
 *
 * - An existing split (identified by 'id') is UPDATED in place — its row id,
 *   and any receipt_items it already carries, survive unless the caller
 *   explicitly sends new ones.
 * - A split with no 'id' is CREATED.
 * - Any split the expense already has that ISN'T present in $splits is
 *   DELETED — this also covers ExpenseForm's old "switched from Split to a
 *   direct project/distribution" branch, which simply stops submitting any
 *   splits at all.
 * - belongs_to_vendor_id is always the acting user's own (hub) vendor —
 *   never the expense's (possibly cross-vendor, material-order) owner
 *   vendor — matching ExpenseForm::save_splits() exactly.
 *
 * Each row in $splits may use either the web's own key spelling
 * ('reimbursment') or the app's ('reimbursement') — whichever is present
 * wins, so callers don't need to translate first.
 */
class ExpenseSplitsWriter
{
    public function __construct(private ExpenseAccessGuard $guard)
    {
    }

    /**
     * @param  list<array{id?: ?int, amount: float|string, project_id?: mixed, distribution_id?: mixed, reimbursment?: ?string, reimbursement?: ?string, note?: ?string, items?: mixed}>  $splits
     */
    public function sync(Expense $expense, array $splits, int $belongsToVendorId, int $createdByUserId): void
    {
        $existingIds = $expense->splits->pluck('id')->map(fn ($id) => (int) $id)->all();
        $keptIds = [];

        foreach ($splits as $split) {
            [$projectId, $distributionId] = $this->guard->resolveProjectSelector(
                $split['project_id'] ?? null,
                $split['distribution_id'] ?? null,
            );

            $this->guard->assertProjectAccessible($projectId, $distributionId);

            $attributes = [
                'amount' => $split['amount'],
                'expense_id' => $expense->id,
                'project_id' => $projectId,
                'distribution_id' => $distributionId,
                'reimbursment' => $this->resolveReimbursement($split),
                'note' => $split['note'] ?? null,
                'belongs_to_vendor_id' => $belongsToVendorId,
                'created_by_user_id' => $createdByUserId,
            ];

            // Only touch receipt_items when the caller actually sent an
            // 'items' key — the web always does (even as null, for a split
            // with no receipt line items), so this preserves its existing
            // exact-dollar behaviour; the app never sends per-split line
            // items at all, so an app save keeps whatever was there.
            if (array_key_exists('items', $split)) {
                $attributes['receipt_items'] = (object) $split['items'];
            }

            $splitId = isset($split['id']) ? (int) $split['id'] : null;

            if ($splitId && in_array($splitId, $existingIds, true)) {
                ExpenseSplits::findOrFail($splitId)->update($attributes);
                $keptIds[] = $splitId;
            } else {
                if (! array_key_exists('receipt_items', $attributes)) {
                    $attributes['receipt_items'] = null;
                }
                ExpenseSplits::create($attributes);
            }
        }

        foreach (array_diff($existingIds, $keptIds) as $staleId) {
            ExpenseSplits::find($staleId)?->delete();
        }
    }

    /**
     * 'None' (not null) when the caller sends neither key — mirrors the
     * web split editor's own default (ExpenseSplitsCreate::addSplits()/
     * addSplit() seed every new split row with 'reimbursment' => 'None').
     *
     * @param  array{reimbursment?: ?string, reimbursement?: ?string}  $split
     */
    private function resolveReimbursement(array $split): string
    {
        $value = $split['reimbursment'] ?? $split['reimbursement'] ?? null;

        return $value === null || $value === '' ? 'None' : $value;
    }
}
