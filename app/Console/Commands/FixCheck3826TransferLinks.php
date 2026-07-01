<?php

namespace App\Console\Commands;

use App\Models\Check;
use App\Models\Transaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixCheck3826TransferLinks extends Command
{
    protected $signature = 'app:fix-check3826-transfer-links
                            {--dry-run : Show what would change without modifying data}';

    protected $description = 'Relink check 3826 to the correct Venmo transfers (28744, 28762, 28789), reindex them, and soft-delete duplicate check 3825';

    public function handle(): int
    {
        $targetCheckId = 3826;
        $duplicateCheckId = 3825;
        $transactionIds = [28744, 28762, 28789];
        $dryRun = (bool) $this->option('dry-run');

        $targetCheck = Check::withoutGlobalScopes()->find($targetCheckId);
        if (! $targetCheck) {
            $this->error("Target check {$targetCheckId} not found. Aborting.");

            return self::FAILURE;
        }

        $duplicateCheck = Check::withoutGlobalScopes()->withTrashed()->find($duplicateCheckId);

        $transactions = Transaction::withoutGlobalScopes()
            ->whereIn('id', $transactionIds)
            ->get()
            ->keyBy('id');

        foreach ($transactionIds as $transactionId) {
            if (! $transactions->has($transactionId)) {
                $this->error("Transaction {$transactionId} not found. Aborting (no changes made).");

                return self::FAILURE;
            }
        }

        $transactionSum = collect($transactionIds)->sum(fn (int $transactionId) => (float) $transactions[$transactionId]->amount);
        if (round($transactionSum, 2) !== round((float) $targetCheck->amount, 2)) {
            $this->error(sprintf(
                'Amount mismatch: transactions %s sum to $%.2f but check %d is $%.2f. Aborting.',
                implode(' + ', $transactionIds),
                $transactionSum,
                $targetCheckId,
                $targetCheck->amount,
            ));

            return self::FAILURE;
        }

        $allLinkedToTarget = collect($transactionIds)->every(
            fn (int $transactionId) => (int) $transactions[$transactionId]->check_id === $targetCheckId
        );

        $duplicateResolved = ! $duplicateCheck
            || $duplicateCheck->trashed()
            || ($duplicateCheck->timesheets()->count() === 0 && $duplicateCheck->transactions()->count() === 0);

        if ($allLinkedToTarget && $duplicateResolved) {
            $this->info("Check {$targetCheckId} is already linked to ".implode(', ', $transactionIds).' and duplicate check '.$duplicateCheckId.' is resolved. Nothing to do.');

            return self::SUCCESS;
        }

        $this->line('Target check: #'.$targetCheckId.' ($'.number_format((float) $targetCheck->amount, 2).')');
        $this->line('Transactions to relink: '.implode(', ', $transactionIds));
        $this->line('Duplicate check to retire: #'.$duplicateCheckId);

        if ($dryRun) {
            $this->warn('DRY RUN — no changes will be made.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($duplicateCheck, $duplicateCheckId, $targetCheckId, $transactionIds): void {
            Transaction::withoutGlobalScopes()
                ->whereIn('id', $transactionIds)
                ->update(['check_id' => $targetCheckId]);

            if ($duplicateCheck && ! $duplicateCheck->trashed()) {
                $duplicateCheck->refresh();

                if ($duplicateCheck->timesheets()->count() !== 0) {
                    throw new \RuntimeException("Duplicate check {$duplicateCheckId} still has linked timesheets. Aborting.");
                }

                if ($duplicateCheck->transactions()->count() !== 0) {
                    throw new \RuntimeException("Duplicate check {$duplicateCheckId} still has linked transactions. Aborting.");
                }

                $duplicateCheck->delete();
            }
        });

        Transaction::withoutGlobalScopes()
            ->whereIn('id', $transactionIds)
            ->searchable();

        $this->info("✓ Linked transactions ".implode(', ', $transactionIds)." to check {$targetCheckId}");

        if ($duplicateCheck && ! $duplicateCheck->trashed()) {
            $this->info("✓ Soft-deleted duplicate check {$duplicateCheckId}");
        } else {
            $this->line("Duplicate check {$duplicateCheckId} was already deleted or missing.");
        }

        return self::SUCCESS;
    }
}