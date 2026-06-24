<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use App\Models\Vendor;
use App\Models\VendorTransaction;
use Illuminate\Console\Command;

class MatchBpSandeTransactions extends Command
{
    protected $signature = 'transactions:match-bp-sande
        {--dry-run : Show what would change without writing}';

    protected $description = 'Link Plaid-masked "& SANDE" BP gas station transactions to vendor 277 (Bp) and add the alias rule so future masked transactions auto-match';

    /**
     * The BP station at Willow & Sanders Rd posts as "BP#9109927WILLOW & SANDE".
     * Plaid now masks the leading "BP#9109927WILLOW" to asterisks, leaving only
     * "& SANDE" visible, so the existing "BP" / "BP#" alias rules no longer match
     * and these transactions land on the Match Vendor screen unmatched.
     *
     * This command adds a VendorTransaction alias on the stable "& SANDE" tail and
     * backfills any currently-unmatched transactions. All historical "& SANDE"
     * transactions have always belonged to vendor 277 (Bp), so the pattern is safe.
     */
    private const VENDOR_ID = 277;

    private const MATCH_DESC = '& SANDE';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $prefix = $dryRun ? '[DRY RUN] ' : '';

        $vendor = Vendor::withoutGlobalScopes()->find(self::VENDOR_ID);

        if (! $vendor) {
            $this->error('Vendor '.self::VENDOR_ID.' (Bp) not found — aborting.');

            return self::FAILURE;
        }

        $this->info("Target vendor: {$vendor->business_name} (#".self::VENDOR_ID.')');
        $this->newLine();

        $this->ensureAliasRule($dryRun, $prefix);
        $this->backfillTransactions($dryRun, $prefix);

        return self::SUCCESS;
    }

    private function ensureAliasRule(bool $dryRun, string $prefix): void
    {
        $existing = VendorTransaction::where('vendor_id', self::VENDOR_ID)
            ->where('desc', self::MATCH_DESC)
            ->whereNull('deposit_check')
            ->first();

        if ($existing) {
            $this->line("Alias rule already exists (VendorTransaction #{$existing->id}) — skipping creation.");

            return;
        }

        if ($dryRun) {
            $this->line($prefix.'Would create VendorTransaction alias "'.self::MATCH_DESC.'" -> vendor '.self::VENDOR_ID);

            return;
        }

        $rule = VendorTransaction::create([
            'vendor_id' => self::VENDOR_ID,
            'deposit_check' => null,
            'amount_sign' => null,
            'plaid_inst_id' => null,
            'desc' => self::MATCH_DESC,
            'options' => json_encode('/i'),
        ]);

        $this->info("Created VendorTransaction alias #{$rule->id}: \"".self::MATCH_DESC.'" -> vendor '.self::VENDOR_ID);
    }

    private function backfillTransactions(bool $dryRun, string $prefix): void
    {
        $transactions = Transaction::withoutGlobalScopes()
            ->whereNull('vendor_id')
            ->whereNull('deleted_at')
            ->where('plaid_merchant_description', 'like', '%'.self::MATCH_DESC.'%')
            ->get();

        if ($transactions->isEmpty()) {
            $this->newLine();
            $this->info('No unmatched "'.self::MATCH_DESC.'" transactions to backfill.');

            return;
        }

        $this->newLine();
        $this->table(
            ['Transaction', 'Date', 'Amount', 'Description'],
            $transactions->map(fn (Transaction $t) => [
                $t->id,
                optional($t->transaction_date)->format('Y-m-d'),
                $t->amount,
                $t->plaid_merchant_description,
            ])->toArray()
        );

        $applied = 0;

        foreach ($transactions as $transaction) {
            if ($dryRun) {
                $this->line($prefix.'Would set vendor_id '.self::VENDOR_ID." on transaction {$transaction->id}");
                $applied++;

                continue;
            }

            $transaction->vendor_id = self::VENDOR_ID;
            $transaction->saveQuietly();
            $transaction->searchable();

            if ($transaction->expense) {
                $expense = $transaction->expense;
                $expense->vendor_id = self::VENDOR_ID;
                $expense->saveQuietly();
                $expense->searchable();
            }

            $this->line('Set vendor_id '.self::VENDOR_ID." on transaction {$transaction->id}");
            $applied++;
        }

        $this->newLine();
        $this->info("{$prefix}Backfilled: {$applied} transaction(s).");
    }
}
