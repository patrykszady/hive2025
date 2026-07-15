<?php

namespace App\Console\Commands;

use App\Models\Expense;
use App\Models\Vendor;
use Illuminate\Console\Command;

/**
 * Mark an expense as owed back by a vendor (reimbursment = 'V:{vendor_id}').
 *
 * Use case: the company pays a bill on a subcontractor's behalf (e.g. GS
 * Construction paid the Village of Northbrook permit fee for PMG Carpentry);
 * the expense then shows as a selectable deduction on /vendors/{id}/payment
 * and is settled (check_id set) when included in a payment.
 *
 * Idempotent; dry-run by default, pass --apply to execute.
 */
class MarkExpenseVendorReimbursement extends Command
{
    protected $signature = 'app:mark-expense-vendor-reimbursement
                            {expense : Expense ID}
                            {vendor : Vendor ID that owes the company back}
                            {--apply : Actually write the change}';

    protected $description = 'Mark an expense as reimbursable by a vendor (reimbursment = V:{vendor_id}) so it deducts from that vendor\'s next payment.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $this->info($apply ? 'APPLY mode — writing changes.' : 'DRY-RUN — pass --apply to write changes.');

        $expense = Expense::withoutGlobalScopes()->find((int) $this->argument('expense'));
        if (! $expense) {
            $this->error('Expense '.$this->argument('expense').' not found.');

            return self::FAILURE;
        }

        $vendor = Vendor::withoutGlobalScopes()->find((int) $this->argument('vendor'));
        if (! $vendor) {
            $this->error('Vendor '.$this->argument('vendor').' not found.');

            return self::FAILURE;
        }

        $target = 'V:'.$vendor->id;
        $current = $expense->getRawOriginal('reimbursment');

        $this->line("Expense {$expense->id}: \${$expense->amount} {$expense->date?->format('Y-m-d')} vendor_id={$expense->vendor_id} invoice=[{$expense->invoice}]");
        $this->line("Vendor {$vendor->id}: {$vendor->business_name}");

        if ($current === $target) {
            $this->info('Already marked — OK.');

            return self::SUCCESS;
        }

        if ($current !== null && trim((string) $current) !== '') {
            $this->error("Expense already has reimbursment [{$current}] — not touching it.");

            return self::FAILURE;
        }

        if ($expense->check_id !== null) {
            $this->error("Expense is already settled by check {$expense->check_id} — refusing.");

            return self::FAILURE;
        }

        if ($expense->paid_by !== null) {
            $this->error("Expense has paid_by={$expense->paid_by} (employee-paid) — refusing.");

            return self::FAILURE;
        }

        $this->line("reimbursment: [".($current ?? 'NULL')."] → [{$target}]");
        if ($apply) {
            $expense->reimbursment = $target;
            $expense->save();
            $expense->searchable();
            $this->info('  → saved. It will appear as a deduction on /vendors/'.$vendor->id.'/payment.');
        }

        return self::SUCCESS;
    }
}
