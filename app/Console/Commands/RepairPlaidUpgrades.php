<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Console\Command;

class RepairPlaidUpgrades extends Command
{
    protected $signature = 'plaid:repair-upgrades {ids* : Transaction IDs to inspect}';
    protected $description = 'Repair pending->posted upgrades that created duplicates; restore pending and merge into posted';

    public function handle(): int
    {
        $ids = collect($this->argument('ids'))->map(fn($v) => (int) $v)->filter();
        if ($ids->isEmpty()) {
            $this->error('No IDs provided');
            return self::FAILURE;
        }

        foreach ($ids as $id) {
            $t = Transaction::withTrashed()->find($id);
            if (!$t) {
                $this->warn("Transaction {$id} not found");
                continue;
            }
            $this->info("Examining {$id} amount={$t->amount} acct={$t->bank_account_id} txn_date={$t->transaction_date} posted={$t->posted_date} deleted={$t->deleted_at}");

            // Find a posted candidate within ±7 days with the same absolute amount on the same account
            $date = $t->transaction_date ? Carbon::parse($t->transaction_date)->toDateString() : null;
            if (!$date) { $this->warn('  Skip: missing transaction_date'); continue; }
            $start = Carbon::parse($date)->copy()->subDays(7)->toDateString();
            $end = Carbon::parse($date)->copy()->addDays(7)->toDateString();
            $candidates = Transaction::query()
                ->where('bank_account_id', $t->bank_account_id)
                ->whereNull('deleted_at')
                ->whereNotNull('posted_date')
                ->whereBetween('transaction_date', [$start, $end])
                ->where(function($q) use ($t) {
                    $q->where('amount', (float) $t->amount)
                      ->orWhere('amount', (float) (-1 * (float) $t->amount));
                })
                ->orderBy('transaction_date')
                ->get();

            if ($candidates->isEmpty()) { $this->warn('  No posted candidate found'); continue; }
            $posted = $candidates->first();
            $this->info("  Found posted {$posted->id} date={$posted->transaction_date} posted={$posted->posted_date} amount={$posted->amount}");

            // If original pending is soft-deleted, restore it so we can merge
            if ($t->deleted_at) {
                $t->restore();
                $this->info('  Restored pending');
            }

            // Preserve original transaction_date from pending; keep posted_date from posted; move plaid ids and details to posted
            $posted->transaction_date = $t->transaction_date;
            // retain posted->posted_date
            $details = is_array($posted->details ?? null) ? $posted->details : (array) json_decode(json_encode($posted->details ?? []), true);
            $details['pending_transaction_id'] = $t->plaid_transaction_id;
            $posted->details = $details;
            $posted->save();
            $this->info('  Updated posted with original txn_date and pending link');

            // Soft-delete the original pending to avoid duplicate active rows
            $t->delete();
            $this->info('  Soft-deleted pending after merge');
        }

        return self::SUCCESS;
    }
}
