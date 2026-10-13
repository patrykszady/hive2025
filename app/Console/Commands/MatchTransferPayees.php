<?php

namespace App\Console\Commands;

use App\Models\BankAccount;
use App\Models\Transaction;
use App\Support\Transactions\TransferPayee;
use Illuminate\Console\Command;

/**
 * Give every person transfer (Zelle, a named transfer) the vendor its payee
 * name points to, where that is unambiguous. Runs inside the scheduled
 * vendor matching too; this is the backfill and the dry run.
 */
class MatchTransferPayees extends Command
{
    protected $signature = 'transactions:match-transfer-payees {--dry-run : List the matches, assign nothing}';

    protected $description = 'Assign vendors to Zelle and other named person transfers from the payee name';

    public function handle(): int
    {
        $accounts = BankAccount::withoutGlobalScopes()->with('bank')->get()->keyBy('id');
        $assigned = 0;
        $unmatched = [];

        Transaction::query()
            ->whereNull('vendor_id')
            ->where('check_number', Transaction::TRANSFER_CHECK_NUMBER)
            ->orderBy('id')
            ->chunkById(500, function ($transactions) use ($accounts, &$assigned, &$unmatched) {
                foreach ($transactions as $transaction) {
                    if ($transaction->transferKind() !== 'person') {
                        continue;
                    }
                    $company = (int) ($accounts->get($transaction->bank_account_id)?->bank?->vendor_id ?? 0);
                    $name = TransferPayee::nameFrom($transaction->plaid_merchant_description);
                    if (! $company || ! $name) {
                        continue;
                    }
                    $vendor = TransferPayee::vendorFor($name, $company);
                    if (! $vendor) {
                        $unmatched[$name] = ($unmatched[$name] ?? 0) + 1;

                        continue;
                    }
                    if (! $this->option('dry-run')) {
                        $transaction->vendor_id = $vendor->id;
                        $transaction->save();
                    }
                    $assigned++;
                    $this->line(sprintf('  %s  $%s  "%s" → %s', $transaction->transaction_date?->format('m/d/Y'), number_format((float) $transaction->amount, 2), $name, $vendor->business_name));
                }
            });

        arsort($unmatched);
        $this->info(($this->option('dry-run') ? 'Would assign ' : 'Assigned ').$assigned.' transfer(s).');
        if ($unmatched !== []) {
            $this->line('Left unassigned (no single vendor, or a name several people share): '.collect($unmatched)->map(fn ($n, $k) => "{$k} ×{$n}")->take(15)->implode(', '));
        }

        return self::SUCCESS;
    }
}
