<?php

namespace App\Console\Commands;

use App\Models\Check;
use App\Models\Expense;
use App\Models\Transaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Move an expense onto the check that really paid it, recalculating both
 * checks. Grzegorz's $2,437.85 Zelle of 08/27/2025 paid back five purchases,
 * but Las Hardwoods ($772.28, 08/26/2025) never went onto that check (id
 * 3492); in November it was put on paper check #2529 (id 3622) and paid a
 * second time. On 3492 it makes the check match its Zelle, and #2529 then
 * shows the $772.28 it overpaid.
 */
class MoveExpenseToCheck extends Command
{
    protected $signature = 'expenses:move-to-check
        {expense : The expense to move}
        {check : The check that paid it}
        {--dry-run : Show what would change, change nothing}';

    protected $description = 'Move an expense onto the check that paid it, recalculating both checks';

    public function handle(): int
    {
        $expense = Expense::withoutGlobalScopes()->whereNull('deleted_at')->find((int) $this->argument('expense'));
        $target = Check::withoutGlobalScopes()->whereNull('deleted_at')->find((int) $this->argument('check'));

        $problem = match (true) {
            ! $expense => 'No such expense.',
            ! $target => 'No such check.',
            (int) $expense->check_id === (int) $target->id => 'The expense is already on that check.',
            (int) $expense->belongs_to_vendor_id !== (int) $target->belongs_to_vendor_id => 'The check belongs to another company than the expense.',
            $expense->paid_by !== null && (int) $expense->paid_by !== (int) $target->user_id => 'The expense was paid by someone else than the check pays.',
            default => null,
        };
        if ($problem !== null) {
            $this->error($problem);

            return self::FAILURE;
        }

        $source = $expense->check_id ? Check::withoutGlobalScopes()->whereNull('deleted_at')->find($expense->check_id) : null;
        $describe = fn (Check $check, float $total) => sprintf('check %d (%s %s): total %s, bank paid %s', $check->id, $check->check_type,
            $check->date?->format('m/d/Y'), money($total), money((float) Transaction::withoutGlobalScopes()->whereNull('deleted_at')->where('check_id', $check->id)->sum('amount')));

        $this->line(sprintf('  expense %d  %s  %s  → check %d', $expense->id, $expense->date?->format('m/d/Y'), money($expense->amount), $target->id));
        if ($this->option('dry-run')) {
            if ($source) {
                $this->line('  then '.$describe($source, (float) $source->amount - (float) $expense->amount));
            }
            $this->line('  then '.$describe($target, (float) $target->amount + (float) $expense->amount));
            $this->info('Dry run: nothing changed.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($expense, $source, $target) {
            $expense->check_id = $target->id;
            $expense->save();
            $source?->recalculateAmount();
            $target->recalculateAmount();
        });

        if ($source) {
            $this->line('  now '.$describe($source->fresh(), (float) $source->fresh()->amount));
        }
        $this->line('  now '.$describe($target->fresh(), (float) $target->fresh()->amount));
        $this->info('Moved.');

        return self::SUCCESS;
    }
}
