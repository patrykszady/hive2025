<?php

namespace App\Console\Commands;

use App\Models\BankAccount;
use App\Models\Check;
use App\Models\Distribution;
use App\Models\Expense;
use App\Models\Project;
use App\Models\Transaction;
use App\Models\Vendor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Record bank payments the way the "Create Expense from Transaction" form
 * does (ExpenseForm::store with "Create New Check"): a check of the kind the
 * transaction is — Transfer for a transfer, Cash for a withdrawal, Check with
 * its number for a paper check — an expense for the payee on it, and the
 * transaction linked to the check. For payments the audit's Missing Expenses
 * lists by the dozen, where the form would take one at a time; a payment
 * whose job is not known yet is recorded without one and shows "No Project".
 */
class RecordTransactionPayment extends Command
{
    protected $signature = 'transactions:record-payment
        {ids* : The bank transactions to record}
        {--vendor= : The vendor that was paid}
        {--project= : The job, when known}
        {--distribution= : A distribution instead of a job (OFFICE for petty cash)}
        {--dry-run : Show what would be recorded, change nothing}';

    protected $description = 'Record bank payments (transfers, cash, paper checks) as checks with an expense for the payee';

    public function handle(): int
    {
        $vendor = Vendor::withoutGlobalScopes()->find((int) $this->option('vendor'));
        $projectId = $this->option('project') ? (int) $this->option('project') : null;
        $distributionId = $this->option('distribution') ? (int) $this->option('distribution') : null;

        if (! $vendor) {
            $this->error('--vendor is required: the vendor that was paid.');

            return self::FAILURE;
        }
        if ($projectId && $distributionId) {
            $this->error('Give a --project or a --distribution, not both.');

            return self::FAILURE;
        }
        if ($projectId && ! Project::withoutGlobalScopes()->whereKey($projectId)->exists()) {
            $this->error("No project {$projectId}.");

            return self::FAILURE;
        }
        if ($distributionId && ! Distribution::withoutGlobalScopes()->whereKey($distributionId)->exists()) {
            $this->error("No distribution {$distributionId}.");

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $recorded = 0;
        $ids = array_values(array_unique(array_map('intval', (array) $this->argument('ids'))));

        foreach ($ids as $id) {
            $transaction = Transaction::withoutGlobalScopes()->setEagerLoads([])->find($id);
            $skip = match (true) {
                ! $transaction => 'no such transaction',
                $transaction->trashed() => 'removed; restore it first',
                $transaction->check_id !== null => "already on check {$transaction->check_id}",
                $transaction->expense_id !== null => "already on expense {$transaction->expense_id}",
                (float) $transaction->amount <= 0 => 'not money paid out',
                default => null,
            };
            if ($skip === null) {
                [$type, $number] = $this->kind($transaction);
                $skip = $type === null ? 'not a check, transfer or cash withdrawal' : null;
            }
            if ($skip === null && $type === 'Check' && Check::withoutGlobalScopes()->whereNull('deleted_at')->where('bank_account_id', $transaction->bank_account_id)->where('check_type', 'Check')->where('check_number', $number)->exists()) {
                $skip = "check #{$number} is already in Hive; link the transaction to it instead";
            }
            if ($skip !== null) {
                $this->warn("  {$id}: {$skip}");

                continue;
            }

            $this->line(sprintf('  %d  %s  %s  %s  → %s%s to %s%s', $id, $transaction->transaction_date->format('m/d/Y'), money($transaction->amount),
                (string) $transaction->plaid_merchant_description, $type, $number ? " #{$number}" : '', $vendor->business_name,
                $dryRun ? '  (dry run)' : ''));
            $recorded++;

            if (! $dryRun) {
                $this->record($transaction, $vendor, $type, $number, $projectId, $distributionId);
            }
        }

        $this->info(sprintf('%s %d of %d.', $dryRun ? 'Would record' : 'Recorded', $recorded, count($ids)));

        return self::SUCCESS;
    }

    /**
     * The check a transaction is, as the expense form reads it.
     *
     * @return array{0: ?string, 1: ?string} [check_type, check_number]
     */
    protected function kind(Transaction $transaction): array
    {
        $number = trim((string) $transaction->check_number);

        return match (true) {
            $number === Transaction::TRANSFER_CHECK_NUMBER => ['Transfer', null],
            $number === Transaction::CASH_CHECK_NUMBER => ['Cash', null],
            ctype_digit($number) => ['Check', $number],
            default => [null, null],
        };
    }

    protected function record(Transaction $transaction, Vendor $vendor, string $type, ?string $number, ?int $projectId, ?int $distributionId): void
    {
        $company = (int) BankAccount::withoutGlobalScopes()->whereKey($transaction->bank_account_id)->value('vendor_id');

        DB::transaction(function () use ($transaction, $vendor, $type, $number, $projectId, $distributionId, $company) {
            $check = Check::create([
                'check_type' => $type,
                'check_number' => $number,
                'date' => $transaction->transaction_date,
                'bank_account_id' => $transaction->bank_account_id,
                'amount' => $transaction->amount,
                'user_id' => null,
                'vendor_id' => $vendor->id,
                'belongs_to_vendor_id' => $company,
                'created_by_user_id' => 0,
            ]);

            Expense::create([
                'amount' => $transaction->amount,
                'date' => $transaction->transaction_date,
                'project_id' => $projectId,
                'distribution_id' => $distributionId,
                'vendor_id' => $vendor->id,
                'check_id' => $check->id,
                'belongs_to_vendor_id' => $company,
                'created_by_user_id' => 0,
            ]);
            $check->recalculateAmount();

            $transaction->check_id = $check->id;
            $transaction->expense_id = null;
            if ((int) $vendor->id !== $company) {
                $transaction->vendor_id = $vendor->id;
            }
            $transaction->save();
        });
    }
}
