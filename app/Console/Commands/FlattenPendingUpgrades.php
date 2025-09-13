<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Legacy cleanup: remove any of the deprecated pending upgrade history structures
 * from details ( _pending_upgrade, _pending_upgrades, _pending_previous_ids ).
 *
 * New approach: Only the top-level pending_transaction_id from Plaid is used.
 * We no longer retain historical chains.
 *
 * Behavior:
 *  - If details not array => skip
 *  - If none of the legacy keys present => skip
 *  - Otherwise unset them and persist (unless --dry-run)
 */
class FlattenPendingUpgrades extends Command
{
    /** @var string */
    protected $signature = 'transactions:purge-legacy-pending-history {--chunk=500 : Number of rows per chunk} {--dry-run : Report changes without saving}';

    /** @var string */
    protected $description = 'Remove legacy pending upgrade history keys from transaction details.';

    public function handle(): int
    {
        $chunkSize = (int) $this->option('chunk');
        $dryRun = (bool) $this->option('dry-run');
        $updated = 0;
        $skipped = 0;
        $already = 0;

    $this->info('Scanning transactions for legacy pending history keys...');
        Transaction::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->whereNotNull('details')
            ->orderBy('id')
            ->chunk($chunkSize, function ($transactions) use (&$updated, &$skipped, &$already, $dryRun) {
                foreach ($transactions as $t) {
                    $details = $t->details;
                    if (!is_array($details)) {
                        $skipped++; // malformed details
                        continue;
                    }

                    $legacyKeys = ['_pending_upgrade','_pending_upgrades','_pending_previous_ids'];
                    $present = array_intersect($legacyKeys, array_keys($details));
                    if (empty($present)) {
                        $skipped++;
                        continue;
                    }

                    foreach ($legacyKeys as $k) {
                        unset($details[$k]);
                    }

                    if ($dryRun) {
                        $this->line("[Dry-Run] Would purge legacy keys from Transaction {$t->id}: " . implode(',', $present));
                    } else {
                        $t->details = $details;
                        // Do not update updated_at
                        $t->timestamps = false; // disables touching timestamps for this save
                        $t->saveQuietly();
                        $this->line("[Purged] Transaction {$t->id}: " . implode(',', $present));
                    }
                    $updated++;
                }
            });

        $this->newLine();
        $this->info('Purge complete.');
        $this->table(['Metric','Count'], [
            ['Updated', $updated],
            ['AlreadyConverted', $already],
            ['Skipped', $skipped],
        ]);

        if ($dryRun) {
            $this->comment('Dry-run mode: no database changes were persisted.');
        }

        return Command::SUCCESS;
    }
}
