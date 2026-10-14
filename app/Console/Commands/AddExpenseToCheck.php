<?php

namespace App\Console\Commands;

use App\Models\Check;
use App\Models\Distribution;
use App\Models\Expense;
use App\Models\Project;
use App\Models\Transaction;
use App\Models\Vendor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Add an expense to an existing check and recalculate it, so a payment the
 * check did not explain is accounted for. Paper check #2529 (id 3622) paid
 * Grzegorz $972.28 for a $200 timesheet and the Las Hardwoods purchase
 * ($772.28) his 08/27/2025 Zelle had already paid back; he kept the second
 * payment, recorded on the check as a "Greg - Home" expense (2026-10-14).
 */
class AddExpenseToCheck extends Command
{
    protected $signature = 'checks:add-expense
        {check : The check}
        {amount : The expense amount}
        {--distribution= : A distribution (OFFICE, "Greg - Home", …)}
        {--project= : Or a job}
        {--vendor= : The vendor (default: the check\'s company)}
        {--date= : The expense date (default: the check\'s date)}
        {--note= : Why}
        {--dry-run : Show what would change, change nothing}';

    protected $description = 'Add an expense to an existing check and recalculate the check';

    public function handle(): int
    {
        $check = Check::withoutGlobalScopes()->whereNull('deleted_at')->find((int) $this->argument('check'));
        $amount = round((float) $this->argument('amount'), 2);
        $distributionId = $this->option('distribution') ? (int) $this->option('distribution') : null;
        $projectId = $this->option('project') ? (int) $this->option('project') : null;

        $problem = match (true) {
            ! $check => 'No such check.',
            $amount == 0.0 => 'The amount cannot be zero.',
            ($distributionId === null) === ($projectId === null) => 'Give a --distribution or a --project (one of them).',
            $distributionId !== null && ! Distribution::withoutGlobalScopes()->where('vendor_id', $check?->belongs_to_vendor_id)->whereKey($distributionId)->exists() => "No distribution {$distributionId} for this company.",
            $projectId !== null && ! Project::withoutGlobalScopes()->whereKey($projectId)->exists() => "No project {$projectId}.",
            default => null,
        };
        $vendorId = $this->option('vendor') ? (int) $this->option('vendor') : (int) $check?->belongs_to_vendor_id;
        if ($problem === null && ! Vendor::withoutGlobalScopes()->whereKey($vendorId)->exists()) {
            $problem = "No vendor {$vendorId}.";
        }
        if ($problem !== null) {
            $this->error($problem);

            return self::FAILURE;
        }

        $bank = (float) Transaction::withoutGlobalScopes()->whereNull('deleted_at')->where('check_id', $check->id)->sum('amount');
        $where = $distributionId ? Distribution::withoutGlobalScopes()->find($distributionId)->name : 'project '.$projectId;
        $this->line(sprintf('  check %d (%s %s, %s, bank paid %s): + %s %s expense → total %s', $check->id, $check->check_type, $check->date?->format('m/d/Y'),
            money($check->amount), money($bank), money($amount), $where, money((float) $check->amount + $amount)));

        if ($this->option('dry-run')) {
            $this->info('Dry run: nothing changed.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($check, $amount, $distributionId, $projectId, $vendorId) {
            Expense::create([
                'amount' => $amount,
                'date' => $this->option('date') ?: $check->date,
                'distribution_id' => $distributionId,
                'project_id' => $projectId,
                'vendor_id' => $vendorId,
                'check_id' => $check->id,
                'belongs_to_vendor_id' => $check->belongs_to_vendor_id,
                'created_by_user_id' => 0,
                'note' => $this->option('note'),
            ]);
            $check->recalculateAmount();
        });

        $this->info(sprintf('Added. Check %d now totals %s; the bank paid %s.', $check->id, money((float) $check->fresh()->amount), money($bank)));

        return self::SUCCESS;
    }
}
