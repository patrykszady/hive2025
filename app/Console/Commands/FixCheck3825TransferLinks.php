<?php

namespace App\Console\Commands;

use App\Models\Check;
use App\Models\Transaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixCheck3825TransferLinks extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:fix-check3825-transfer-links';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Relink check 3825 to the two $100 Venmo transfers (28744, 28762) and unlink the wrong $200 transfer (28789)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $checkId = 3825;
        $correctIds = [28744, 28762];
        $wrongId = 28789;

        $check = Check::withoutGlobalScopes()->find($checkId);
        if (! $check) {
            $this->error("Check {$checkId} not found. Aborting.");

            return self::FAILURE;
        }

        $transactions = Transaction::withoutGlobalScopes()
            ->whereIn('id', array_merge($correctIds, [$wrongId]))
            ->get()
            ->keyBy('id');

        foreach (array_merge($correctIds, [$wrongId]) as $id) {
            if (! $transactions->has($id)) {
                $this->error("Transaction {$id} not found. Aborting (no changes made).");

                return self::FAILURE;
            }
        }

        $sum = collect($correctIds)->sum(fn ($id) => (float) $transactions[$id]->amount);
        if (round($sum, 2) !== round((float) $check->amount, 2)) {
            $this->error(sprintf(
                'Amount mismatch: transactions %s sum to $%.2f but check %d is $%.2f. Aborting.',
                implode(' + ', $correctIds),
                $sum,
                $checkId,
                $check->amount
            ));

            return self::FAILURE;
        }

        $alreadyCorrect = $transactions[$correctIds[0]]->check_id === $checkId
            && $transactions[$correctIds[1]]->check_id === $checkId
            && $transactions[$wrongId]->check_id === null;

        if ($alreadyCorrect) {
            $this->info("Check {$checkId} is already linked to ".implode(', ', $correctIds).' and '.$wrongId.' is unlinked. Nothing to do.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($checkId, $correctIds, $wrongId) {
            Transaction::withoutGlobalScopes()->where('id', $wrongId)->update(['check_id' => null]);
            Transaction::withoutGlobalScopes()->whereIn('id', $correctIds)->update(['check_id' => $checkId]);
        });

        Transaction::withoutGlobalScopes()
            ->whereIn('id', array_merge($correctIds, [$wrongId]))
            ->searchable();

        $this->info("\u{2713} Linked transactions ".implode(', ', $correctIds)." to check {$checkId}");
        $this->info("\u{2713} Unlinked transaction {$wrongId} from check {$checkId}");

        return self::SUCCESS;
    }
}
