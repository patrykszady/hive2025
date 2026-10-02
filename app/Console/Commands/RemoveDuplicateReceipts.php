<?php

namespace App\Console\Commands;

use App\Models\ExpenseReceipts;
use Illuminate\Console\Command;

/**
 * Removes a receipt attached to an expense twice: the same file on the same
 * expense. On 2026-10-02 two Menards batches were imported side by side and
 * expenses 28646, 28647 and 28648 each got two identical receipt rows (the
 * import now runs one batch at a time). The oldest row stays; the copies are
 * soft-deleted, so nothing is lost, and the file on disk is shared and kept.
 *
 * Dry run unless --commit. Re-running is harmless.
 */
class RemoveDuplicateReceipts extends Command
{
    protected $signature = 'receipts:remove-duplicates
        {--expense=* : Only these expense IDs}
        {--commit : Delete the copies (default: list them)}';

    protected $description = 'Soft-delete receipts attached to the same expense twice (same file), keeping the oldest';

    public function handle(): int
    {
        $query = ExpenseReceipts::query()
            ->whereNotNull('receipt_filename')
            ->where('receipt_filename', '!=', '')
            ->orderBy('id');

        if ($expenseIds = array_filter(array_map('intval', (array) $this->option('expense')))) {
            $query->whereIn('expense_id', $expenseIds);
        }

        $copies = $query->get(['id', 'expense_id', 'receipt_filename'])
            ->groupBy(fn (ExpenseReceipts $receipt) => $receipt->expense_id.'|'.$receipt->receipt_filename)
            ->filter(fn ($group) => $group->count() > 1)
            ->flatMap(fn ($group) => $group->slice(1));

        if ($copies->isEmpty()) {
            $this->info('No receipt is attached to an expense twice.');

            return self::SUCCESS;
        }

        foreach ($copies as $copy) {
            $this->line(sprintf(
                '%s receipt %d on expense %d (%s)',
                $this->option('commit') ? 'Removing' : 'Would remove',
                $copy->id,
                $copy->expense_id,
                $copy->receipt_filename,
            ));

            if ($this->option('commit')) {
                $copy->delete();
            }
        }

        $this->info(sprintf('%d duplicate receipt(s) %s.', $copies->count(), $this->option('commit') ? 'removed' : 'found — rerun with --commit to remove them'));

        return self::SUCCESS;
    }
}
