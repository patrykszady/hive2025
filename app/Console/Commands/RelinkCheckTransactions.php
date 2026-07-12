<?php

namespace App\Console\Commands;

use App\Models\Check;
use App\Models\Transaction;
use Illuminate\Console\Command;

class RelinkCheckTransactions extends Command
{
    protected $signature = 'app:relink-check-transactions
        {check : Check id to relink}
        {--txn=* : Transaction ids that SHOULD be linked to the check}
        {--apply : Actually relink (default is a dry-run report)}';

    protected $description = 'Replace a check\'s linked transactions with the given set (e.g. a Transfer check that subset-sum matched to the wrong person\'s transfers before the payee gate existed). Validates the new set sums to the check amount and sits on the same bank. Idempotent — safe to re-run.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $check = Check::withoutGlobalScopes()->whereNull('deleted_at')->find((int) $this->argument('check'));

        if (! $check) {
            $this->error('Check not found.');

            return self::FAILURE;
        }

        $wantedIds = collect($this->option('txn'))->map(fn ($id) => (int) $id)->filter()->unique()->values();

        if ($wantedIds->isEmpty()) {
            $this->error('Pass at least one --txn=<id>.');

            return self::FAILURE;
        }

        $wanted = Transaction::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->whereIn('id', $wantedIds)
            ->get();

        if ($wanted->count() !== $wantedIds->count()) {
            $this->error('Missing transactions: [' . $wantedIds->diff($wanted->pluck('id'))->implode(', ') . '].');

            return self::FAILURE;
        }

        // The new set must exactly cover the check amount and live on the check's bank.
        if (number_format((float) $wanted->sum('amount'), 2, '.', '') !== number_format((float) $check->amount, 2, '.', '')) {
            $this->error(sprintf('Refusing: transactions sum $%s but check %d is $%s.', $wanted->sum('amount'), $check->id, $check->amount));

            return self::FAILURE;
        }

        $bankAccountIds = $check->bank_account_id
            ? $check->bank_account->bank->accounts->pluck('id')
            : collect();

        if ($bankAccountIds->isNotEmpty() && $wanted->pluck('bank_account_id')->diff($bankAccountIds)->isNotEmpty()) {
            $this->error('Refusing: some transactions are on a different bank than the check.');

            return self::FAILURE;
        }

        $claimed = $wanted->filter(fn ($t) => $t->check_id && (int) $t->check_id !== (int) $check->id);
        if ($claimed->isNotEmpty()) {
            $this->error('Refusing: transactions already linked to other checks: [' .
                $claimed->map(fn ($t) => "{$t->id}→check {$t->check_id}")->implode(', ') . '].');

            return self::FAILURE;
        }

        $current = Transaction::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('check_id', $check->id)
            ->get();

        $unlink = $current->whereNotIn('id', $wantedIds);
        $link = $wanted->filter(fn ($t) => (int) $t->check_id !== (int) $check->id);

        if ($unlink->isEmpty() && $link->isEmpty()) {
            $this->info("Check {$check->id} already has exactly these transactions — nothing to do.");

            return self::SUCCESS;
        }

        $this->line(sprintf(
            'Check %d ($%s, %s): unlinking [%s], linking [%s].',
            $check->id,
            $check->amount,
            $check->date->toDateString(),
            $unlink->pluck('id')->implode(', ') ?: 'none',
            $link->pluck('id')->implode(', ') ?: 'none',
        ));

        if (! $apply) {
            $this->warn('Dry-run only. Re-run with --apply to relink.');

            return self::SUCCESS;
        }

        foreach ($unlink as $transaction) {
            $transaction->check_id = null;
            $transaction->save();
        }

        foreach ($link as $transaction) {
            $transaction->check_id = $check->id;
            $transaction->save();
        }

        $this->info("Check {$check->id} now linked to [" .
            Transaction::withoutGlobalScopes()->whereNull('deleted_at')->where('check_id', $check->id)->pluck('id')->implode(', ') . '].');

        return self::SUCCESS;
    }
}
