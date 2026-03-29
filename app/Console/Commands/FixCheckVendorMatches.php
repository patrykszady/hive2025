<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use Illuminate\Console\Command;

class FixCheckVendorMatches extends Command
{
    protected $signature = 'transactions:fix-check-vendor-matches
        {--dry-run : Show what would be fixed without making changes}';

    protected $description = 'One-time fix: clear vendor_id and plaid_merchant_name on check transactions that were incorrectly auto-matched (March 2026)';

    /**
     * Transactions 28402 and 28403 are CHECK transactions that Plaid labeled
     * with plaid_merchant_name "Munchs Supply". The auto-match code incorrectly
     * assigned vendor_id 161 (Munchs Supply) to these checks.
     *
     * The root cause (missing check_number guard in TransactionController@line448)
     * has been fixed to prevent recurrence.
     */
    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $fixes = [
            ['transaction_id' => 28402, 'reason' => 'CHECK 2484 — incorrectly auto-matched to Munchs Supply via plaid_merchant_name'],
            ['transaction_id' => 28403, 'reason' => 'CHECK 2579 — incorrectly auto-matched to Munchs Supply via plaid_merchant_name'],
        ];

        $this->table(
            ['Transaction', 'Reason'],
            collect($fixes)->map(fn ($f) => [$f['transaction_id'], $f['reason']])->toArray()
        );

        $this->newLine();

        $applied = 0;
        $skipped = 0;

        foreach ($fixes as $fix) {
            $transaction = Transaction::withoutGlobalScopes()->find($fix['transaction_id']);

            if (! $transaction) {
                $this->warn("Transaction {$fix['transaction_id']} not found — skipping.");
                $skipped++;

                continue;
            }

            if (empty($transaction->check_number)) {
                $this->warn("Transaction {$fix['transaction_id']} has no check_number — not a check transaction. Skipping.");
                $skipped++;

                continue;
            }

            if (empty($transaction->vendor_id) && empty($transaction->plaid_merchant_name)) {
                $this->warn("Transaction {$fix['transaction_id']} already cleared — skipping.");
                $skipped++;

                continue;
            }

            if ($dryRun) {
                $this->line("[DRY RUN] Would clear vendor_id ({$transaction->vendor_id}) and plaid_merchant_name ({$transaction->plaid_merchant_name}) on transaction {$fix['transaction_id']}");
            } else {
                $transaction->vendor_id = null;
                $transaction->plaid_merchant_name = null;
                $transaction->saveQuietly();
                $transaction->searchable();
                $this->line("Cleared vendor_id and plaid_merchant_name on transaction {$fix['transaction_id']}");
            }

            $applied++;
        }

        $this->newLine();
        $prefix = $dryRun ? '[DRY RUN] ' : '';
        $this->info("{$prefix}Applied: {$applied}, Skipped: {$skipped}");

        if (! $dryRun && $applied > 0) {
            $this->info('Scout search index updated for affected transactions.');
        }

        return self::SUCCESS;
    }
}
