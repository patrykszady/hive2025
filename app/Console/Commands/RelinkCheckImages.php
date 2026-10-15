<?php

namespace App\Console\Commands;

use App\Models\Check;
use App\Models\CheckImage;
use App\Models\Transaction;
use Illuminate\Console\Command;

/**
 * Put back the check (and transaction) a matched check image points at.
 *
 * The matcher records its match twice: in check_id / transaction_id and in
 * match_details. On prod all 79 images had lost check_id and transaction_id
 * while keeping their "matched" status and details (found 2026-10-15), so no
 * scan showed on any check page. Restores the columns from match_details,
 * only where the check or transaction still exists and is not deleted.
 * Re-runnable: rows already linked are left alone.
 */
class RelinkCheckImages extends Command
{
    protected $signature = 'cu:relink-check-images
        {--dry-run : Show what would be restored, change nothing}';

    protected $description = 'Restore check_id / transaction_id on matched check images from their recorded match';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $restored = 0;
        $skipped = 0;

        $images = CheckImage::query()
            ->whereIn('match_status', [
                CheckImage::STATUS_MATCHED_CHECK,
                CheckImage::STATUS_MATCHED_CHECK_NUMBER_ONLY,
                CheckImage::STATUS_MATCHED_TRANSACTION,
                CheckImage::STATUS_MANUAL,
            ])
            ->whereNull('check_id')
            ->orderBy('id')
            ->get();

        foreach ($images as $image) {
            $details = (array) ($image->match_details ?? []);
            $checkId = isset($details['check_id']) ? (int) $details['check_id'] : null;
            $transactionId = isset($details['transaction_id']) ? (int) $details['transaction_id'] : null;

            $check = $checkId ? Check::withoutGlobalScopes()->whereNull('deleted_at')->find($checkId) : null;
            $transaction = $transactionId ? Transaction::withoutGlobalScopes()->whereNull('deleted_at')->find($transactionId) : null;

            if (! $check && $transaction && (int) $image->transaction_id === $transaction->id) {
                continue; // a transaction-only match, already linked
            }

            if (! $check && ! $transaction) {
                $skipped++;
                $this->line("  skip image {$image->id}: its recorded check/transaction is gone");

                continue;
            }

            $this->line(sprintf('  image %d (%s) → %s%s', $image->id, $image->image_filename,
                $check ? "check {$check->id}" : '', $transaction ? ($check ? ', ' : '')."transaction {$transaction->id}" : ''));

            if (! $dryRun) {
                $image->forceFill([
                    'check_id' => $check?->id,
                    'transaction_id' => $image->transaction_id ?? $transaction?->id,
                ])->saveQuietly();
            }
            $restored++;
        }

        $this->info(($dryRun ? '[dry run] ' : '')."Restored {$restored} image link(s); skipped {$skipped}.");

        return self::SUCCESS;
    }
}
