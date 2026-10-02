<?php

namespace App\Console\Commands;

use App\Models\Expense;
use App\Models\Transaction;
use App\Scopes\TransactionScope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Moves bank charges onto the expenses they really paid, or off an expense,
 * for links the bank sync or a matcher got wrong. First used 2026-10-02:
 * Plaid "posted" pending card charges as other merchants (a Prime Video
 * charge as an incoming wire fee, an Amazon order as a Lyft ride), the
 * links stayed on the wrong charge and the real charges sat unlinked.
 *
 *   php artisan transactions:relink 29596:28405 29577:none --clear-vendor
 *
 * All pairs are checked before any is written, and they are written
 * together or not at all. --clear-vendor also clears the vendor on charges
 * being unlinked, so vendor matching re-derives it from the description.
 * Re-running is harmless.
 */
class RelinkTransactions extends Command
{
    protected $signature = 'transactions:relink
        {links* : transaction_id:expense_id pairs, or transaction_id:none to unlink}
        {--clear-vendor : Also clear the vendor on charges being unlinked}
        {--dry-run : Show what would change without writing}';

    protected $description = 'Move bank charges to the expenses they paid, or unlink them';

    public function handle(): int
    {
        $pairs = [];

        foreach ($this->argument('links') as $link) {
            if (! preg_match('/^(\d+):(\d+|none)$/', $link, $m)) {
                $this->error("\"{$link}\" is not transaction_id:expense_id or transaction_id:none. Nothing changed.");

                return self::FAILURE;
            }

            $pairs[(int) $m[1]] = $m[2] === 'none' ? null : (int) $m[2];
        }

        $transactions = Transaction::withoutGlobalScope(TransactionScope::class)->with('bank_account.bank')->whereIn('id', array_keys($pairs))->get()->keyBy('id');
        $expenses = Expense::withoutGlobalScopes()->whereNull('deleted_at')->whereIn('id', array_filter($pairs))->get()->keyBy('id');

        foreach ($pairs as $transactionId => $expenseId) {
            $transaction = $transactions->get($transactionId);

            if (! $transaction) {
                $this->error("Transaction {$transactionId} not found (or deleted). Nothing changed.");

                return self::FAILURE;
            }

            if ($expenseId !== null) {
                $expense = $expenses->get($expenseId);

                if (! $expense) {
                    $this->error("Expense {$expenseId} not found (or deleted). Nothing changed.");

                    return self::FAILURE;
                }

                if ((int) $transaction->bank_account?->bank?->vendor_id !== (int) $expense->belongs_to_vendor_id) {
                    $this->error("Transaction {$transactionId} is another company's bank account than expense {$expenseId}. Nothing changed.");

                    return self::FAILURE;
                }
            }
        }

        $touchedExpenses = [];
        $changes = [];

        foreach ($pairs as $transactionId => $expenseId) {
            $transaction = $transactions[$transactionId];
            $label = sprintf('Transaction %d ($%s, %s, %s)', $transaction->id, number_format((float) $transaction->amount, 2), $transaction->transaction_date?->toDateString() ?? '—', mb_strimwidth((string) $transaction->plaid_merchant_description, 0, 40, '…'));

            if ($transaction->expense_id === $expenseId || ($expenseId !== null && (int) $transaction->expense_id === $expenseId)) {
                $this->line(sprintf('%s: already %s.', $label, $expenseId === null ? 'unlinked' : "on expense {$expenseId}"));

                continue;
            }

            $this->line(sprintf('%s: expense %s → %s%s', $label, $transaction->expense_id ?? 'none', $expenseId ?? 'none', $this->option('dry-run') ? ' (dry run)' : ''));
            $touchedExpenses[] = $transaction->expense_id;
            $touchedExpenses[] = $expenseId;
            $changes[$transactionId] = $expenseId;
        }

        if ($this->option('dry-run') || $changes === []) {
            return self::SUCCESS;
        }

        DB::transaction(function () use ($changes, $transactions) {
            foreach ($changes as $transactionId => $expenseId) {
                $transaction = $transactions[$transactionId];

                if ($expenseId === null && $this->option('clear-vendor')) {
                    $transaction->vendor_id = null;
                }

                $transaction->expense_id = $expenseId;
                // A person decided this link; the automatic-link guards stand aside.
                $transaction->manualExpenseLink = true;
                $transaction->save();
            }
        });

        Expense::withoutGlobalScopes()->whereIn('id', array_values(array_unique(array_filter($touchedExpenses))))->searchable();

        return self::SUCCESS;
    }
}
