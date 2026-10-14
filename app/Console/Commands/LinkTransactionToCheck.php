<?php

namespace App\Console\Commands;

use App\Models\BankAccount;
use App\Models\Check;
use App\Models\Distribution;
use App\Models\Expense;
use App\Models\Transaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Link a bank payment to the check it paid when the matcher could not: the
 * amounts differ, so it never paired them. Grzegorz's $1,035.50 Zelle on
 * 05/15/2026 paid timesheet check #3800 ($1,032.50) — $3 more. With --adjust
 * the difference the bank paid on top becomes an OFFICE adjustment expense on
 * the check, so the check total matches the bank again.
 */
class LinkTransactionToCheck extends Command
{
    protected $signature = 'transactions:link-check
        {transaction : The bank payment}
        {check : The check it paid}
        {--adjust : Record what the bank paid beyond the check as an OFFICE adjustment expense}
        {--dry-run : Show what would change, change nothing}';

    protected $description = 'Link a bank payment to the check it paid, with an OFFICE adjustment for a difference';

    public function handle(): int
    {
        $transaction = Transaction::withoutGlobalScopes()->setEagerLoads([])->find((int) $this->argument('transaction'));
        $check = Check::withoutGlobalScopes()->whereNull('deleted_at')->find((int) $this->argument('check'));

        $problem = match (true) {
            ! $transaction => 'No such transaction.',
            ! $check => 'No such check.',
            $transaction->trashed() => 'The transaction was removed; restore it first.',
            $transaction->check_id !== null => "The transaction is already on check {$transaction->check_id}.",
            $transaction->expense_id !== null => "The transaction is already on expense {$transaction->expense_id}.",
            (float) $transaction->amount <= 0 => 'The transaction is not money paid out.',
            default => null,
        };
        $company = $transaction ? (int) BankAccount::withoutGlobalScopes()->whereKey($transaction->bank_account_id)->value('vendor_id') : 0;
        if ($problem === null && (int) $check->belongs_to_vendor_id !== $company) {
            $problem = 'The check belongs to another company than the bank account.';
        }
        if ($problem !== null) {
            $this->error($problem);

            return self::FAILURE;
        }

        $linked = (float) Transaction::withoutGlobalScopes()->whereNull('deleted_at')->where('check_id', $check->id)->sum('amount');
        $difference = round($linked + (float) $transaction->amount - (float) $check->amount, 2);
        $office = Distribution::withoutGlobalScopes()->where('vendor_id', $company)->where('name', 'OFFICE')->value('id');

        if ($difference > 0 && ! $this->option('adjust')) {
            $this->error(sprintf('The bank paid %s more than the check. Add --adjust to record the difference as an OFFICE adjustment.', money($difference)));

            return self::FAILURE;
        }
        if ($difference > 0 && ! $office) {
            $this->error('The company has no OFFICE distribution for the adjustment.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $this->line(sprintf('  %s  %s  %s  → check %d (%s %s, %s)', $transaction->transaction_date->format('m/d/Y'), money($transaction->amount),
            (string) $transaction->plaid_merchant_description, $check->id, $check->check_type, $check->date?->format('m/d/Y'), money($check->amount)));
        if ($difference > 0) {
            $this->line(sprintf('  + OFFICE adjustment expense %s on the check, which then totals %s', money($difference), money((float) $check->amount + $difference)));
        } elseif ($difference < 0) {
            $this->line(sprintf('  The check keeps %s without a bank payment.', money(-$difference)));
        }

        if ($dryRun) {
            $this->info('Dry run: nothing changed.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($transaction, $check, $difference, $office, $company) {
            if ($difference > 0) {
                Expense::create([
                    'amount' => $difference,
                    'date' => $transaction->transaction_date,
                    'distribution_id' => $office,
                    'vendor_id' => $company,
                    'check_id' => $check->id,
                    'belongs_to_vendor_id' => $company,
                    'created_by_user_id' => 0,
                    'note' => sprintf('Adjustment: the bank paid %s for this %s check', money($transaction->amount), money($check->amount)),
                ]);
                $check->recalculateAmount();
            }

            $transaction->check_id = $check->id;
            $transaction->save();
        });

        $this->info(sprintf('Linked. Check %d now totals %s.', $check->id, money((float) $check->fresh()->amount)));

        return self::SUCCESS;
    }
}
