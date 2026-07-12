<?php

namespace App\Console\Commands;

use App\Models\Expense;
use App\Models\Transaction;
use Illuminate\Console\Command;

class MergeDuplicateExpense extends Command
{
    protected $signature = 'app:merge-duplicate-expense
        {remove : Expense id to merge away (its transactions move to --into)}
        {into : Expense id to keep}
        {--apply : Actually merge (default is a dry-run report)}';

    protected $description = 'Merge a duplicate expense into the one to keep: moves its transactions over and soft-deletes it. Refuses unless both expenses have the same vendor and amount and the duplicate has no receipts. Idempotent — safe to re-run.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $remove = Expense::withoutGlobalScopes()->find((int) $this->argument('remove'));
        $keep = Expense::withoutGlobalScopes()->find((int) $this->argument('into'));

        if (! $keep || $keep->deleted_at) {
            $this->error('Keep expense not found or deleted.');

            return self::FAILURE;
        }

        if (! $remove) {
            $this->error('Duplicate expense not found.');

            return self::FAILURE;
        }

        if ($remove->deleted_at) {
            $this->info("Expense {$remove->id} is already deleted — nothing to merge.");

            return self::SUCCESS;
        }

        if ((int) $remove->vendor_id !== (int) $keep->vendor_id
            || $remove->amount !== $keep->amount
            || ! $remove->date->isSameDay($keep->date)) {
            $this->error(sprintf(
                'Refusing: expenses differ (remove: vendor %s $%s %s, keep: vendor %s $%s %s).',
                $remove->vendor_id, $remove->amount, $remove->date->toDateString(),
                $keep->vendor_id, $keep->amount, $keep->date->toDateString(),
            ));

            return self::FAILURE;
        }

        if ($remove->receipts()->exists()) {
            $this->error("Refusing: expense {$remove->id} has receipts attached — merge those manually first.");

            return self::FAILURE;
        }

        $transactions = Transaction::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('expense_id', $remove->id)
            ->get();

        $this->line(sprintf(
            'Merging expense %d into %d ($%s, vendor %s): moving transactions [%s], then soft-deleting %d.',
            $remove->id, $keep->id, $keep->amount, $keep->vendor_id,
            $transactions->pluck('id')->implode(', ') ?: 'none',
            $remove->id,
        ));

        if (! $apply) {
            $this->warn('Dry-run only. Re-run with --apply to merge.');

            return self::SUCCESS;
        }

        foreach ($transactions as $transaction) {
            $transaction->expense_id = $keep->id;
            $transaction->save();
        }

        $remove->delete();
        $keep->searchable();

        $this->info("Merged. Expense {$keep->id} now has transactions [" .
            Transaction::withoutGlobalScopes()->whereNull('deleted_at')->where('expense_id', $keep->id)->pluck('id')->implode(', ') . '].');

        return self::SUCCESS;
    }
}
