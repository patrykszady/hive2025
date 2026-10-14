<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use Illuminate\Console\Command;

/**
 * Bring back bank transactions Plaid removed that were real. Between July
 * and September 2026 Plaid (Capital One, card 4060) removed charges about
 * two weeks after sending them and never sent them again — purchases and the
 * monthly interest, each with its expense, which kept no bank record. A
 * removed card payment is restored by the card-payment pairing on its own;
 * this is for the rest, by id, once the statement shows the charge.
 */
class RestoreRemovedTransactions extends Command
{
    protected $signature = 'transactions:restore-removed
        {ids* : Ids of the removed (soft-deleted) transactions to bring back}
        {--dry-run : Show what would be restored, change nothing}';

    protected $description = 'Restore bank transactions Plaid removed that were real charges';

    public function handle(): int
    {
        $ids = array_values(array_unique(array_map('intval', (array) $this->argument('ids'))));
        $found = Transaction::withoutGlobalScopes()->setEagerLoads([])->whereIn('id', $ids)->get()->keyBy('id');
        $restored = 0;

        foreach ($ids as $id) {
            $transaction = $found->get($id);
            if (! $transaction) {
                $this->warn("  {$id}: no such transaction");

                continue;
            }

            $line = sprintf('%d  %s  %s  %s%s', $id, $transaction->transaction_date?->format('m/d/Y'), money($transaction->amount),
                (string) $transaction->plaid_merchant_description, $transaction->expense_id ? "  (expense {$transaction->expense_id})" : '');

            if (! $transaction->trashed()) {
                $this->line("  {$line}: not removed, left as is");

                continue;
            }

            if (! $this->option('dry-run')) {
                $transaction->restore();
            }
            $restored++;
            $this->line("  {$line}: ".($this->option('dry-run') ? 'would be restored' : 'restored'));
        }

        $this->info(sprintf('%s %d of %d.', $this->option('dry-run') ? 'Would restore' : 'Restored', $restored, count($ids)));

        return self::SUCCESS;
    }
}
