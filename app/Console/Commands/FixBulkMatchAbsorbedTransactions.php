<?php

namespace App\Console\Commands;

use App\Models\Expense;
use App\Models\Transaction;
use Illuminate\Console\Command;

class FixBulkMatchAbsorbedTransactions extends Command
{
    protected $signature = 'app:fix-bulk-match-absorbed-transactions
        {--apply : Actually unlink the extra transactions (default is a dry-run report)}
        {--expense=* : Only process these expense ids (omit to sweep everything)}';

    protected $description = 'Unlink extra transactions wrongly absorbed into one expense by transaction_vendor_bulk_match: an expense whose amount equals each linked transaction can only be covered by ONE of them. Keeps the transaction closest to the expense date and unlinks the rest so the (fixed) bulk-match cron creates their own expenses. Idempotent — safe to re-run.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $onlyExpenseIds = array_map('intval', (array) $this->option('expense'));

        // Bug signature: expense has 2+ linked transactions, each with the
        // exact same amount as the expense itself (N charges piled onto one).
        // Only the vendor-visibility scope is dropped — soft-deleted
        // transactions must NOT count as duplicates.
        $expenses = Expense::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->when($onlyExpenseIds !== [], fn ($q) => $q->whereIn('id', $onlyExpenseIds))
            ->whereHas('transactions', fn ($q) => $q
                ->withoutGlobalScope(\App\Scopes\TransactionScope::class)
                ->whereColumn('transactions.amount', 'expenses.amount'), '>=', 2)
            ->with(['transactions' => fn ($q) => $q->withoutGlobalScope(\App\Scopes\TransactionScope::class)])
            ->get()
            ->filter(fn (Expense $expense) => $expense->transactions
                ->where('amount', $expense->amount)
                ->count() >= 2);

        if ($expenses->isEmpty()) {
            $this->info('No expenses with absorbed duplicate transactions found.');

            return self::SUCCESS;
        }

        $unlinkedCount = 0;

        foreach ($expenses as $expense) {
            // Keep preference: a transaction whose vendor matches the expense
            // beats one that doesn't (e.g. a $12 Microsoft MSBILL charge wins
            // over a $12 bank fee mislinked to a Microsoft expense), then
            // closest to the expense date, then lowest id.
            $sameAmount = $expense->transactions
                ->where('amount', $expense->amount)
                ->sortBy(fn (Transaction $t) => [
                    $t->vendor_id === $expense->vendor_id ? 0 : 1,
                    abs($expense->date->floatDiffInDays($t->transaction_date)),
                    $t->id,
                ])
                ->values();

            $keep = $sameAmount->first();
            $unlink = $sameAmount->slice(1);

            $this->line(sprintf(
                'Expense %d (%s, $%s, vendor %s): %d same-amount transactions — keeping %d, unlinking %s',
                $expense->id,
                $expense->date->toDateString(),
                $expense->amount,
                $expense->vendor_id ?? '-',
                $sameAmount->count(),
                $keep->id,
                $unlink->pluck('id')->implode(', '),
            ));

            if (! $apply) {
                continue;
            }

            foreach ($unlink as $transaction) {
                $transaction->expense_id = null;
                $transaction->save(); // model events keep Scout in sync
                $unlinkedCount++;
            }
        }

        if ($apply) {
            $this->info("Unlinked {$unlinkedCount} transactions from {$expenses->count()} expenses.");
            $this->info('The fixed transaction_vendor_bulk_match run will create their own expenses.');
        } else {
            $this->warn('Dry-run only. Re-run with --apply to unlink.');
        }

        return self::SUCCESS;
    }
}
