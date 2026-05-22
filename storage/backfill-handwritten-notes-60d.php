<?php
declare(strict_types=1);

/**
 * Backfill handwritten_notes on ExpenseReceipts created in the last 60 days
 * by re-running the CU extractor (now with the strengthened HandwrittenNote
 * prompt in hive_Receipts_1).
 *
 * Usage (production):
 *   php artisan tinker --execute="require base_path('storage/backfill-handwritten-notes-60d.php');"
 *
 * Env knobs (optional):
 *   DRY_RUN=1   Only print proposed changes, don't save.
 *   DAYS=60     Look-back window (default 60).
 *   LIMIT=0     Cap number of receipts processed (0 = no cap).
 *   ONLY_NEW=1  Only process receipts whose current handwritten_notes is empty.
 */

use App\Http\Controllers\ReceiptController;
use App\Models\ExpenseReceipts;
use Illuminate\Support\Carbon;

$dryRun  = (bool) (getenv('DRY_RUN') ?: 0);
$days    = (int) (getenv('DAYS') ?: 60);
$limit   = (int) (getenv('LIMIT') ?: 0);
$onlyNew = (bool) (getenv('ONLY_NEW') ?: 0);

$cutoff = Carbon::now()->subDays($days);
$ctrl   = app(ReceiptController::class);

$query = ExpenseReceipts::query()
    ->where('created_at', '>=', $cutoff)
    ->whereNotNull('receipt_filename')
    ->where('receipt_filename', '!=', '')
    ->orderBy('id');

if ($limit > 0) {
    $query->limit($limit);
}

$total     = (clone $query)->count();
$scanned   = 0;
$changed   = 0;
$skipped   = 0;
$failed    = 0;
$noteAdded = 0;

echo "Backfill handwritten_notes — last {$days}d (since {$cutoff->toDateTimeString()})\n";
echo "Matching receipts: {$total}" . ($dryRun ? '  [DRY RUN]' : '') . ($onlyNew ? '  [ONLY_NEW]' : '') . "\n\n";

$query->chunkById(50, function ($chunk) use (
    $ctrl, $dryRun, $onlyNew,
    &$scanned, &$changed, &$skipped, &$failed, &$noteAdded
) {
    foreach ($chunk as $r) {
        $scanned++;
        $items = $r->receipt_items;
        if (! is_array($items)) {
            $items = (array) ($items ?? []);
        }
        $currentNotes = isset($items['handwritten_notes']) ? (array) $items['handwritten_notes'] : [];

        if ($onlyNew && ! empty($currentNotes)) {
            $skipped++;
            continue;
        }

        $path = 'receipts/' . $r->receipt_filename;

        try {
            $res = $ctrl->extractReceipt($path, 'receipt');
        } catch (\Throwable $e) {
            $failed++;
            echo "  [FAIL] id={$r->id} {$r->receipt_filename} — " . $e->getMessage() . "\n";
            continue;
        }

        $newNotes = (array) ($res['fields']['handwritten_notes'] ?? []);

        $beforeJson = json_encode($currentNotes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $afterJson  = json_encode($newNotes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($beforeJson === $afterJson) {
            continue;
        }

        $changed++;
        if (empty($currentNotes) && ! empty($newNotes)) {
            $noteAdded++;
        }

        echo "  id={$r->id}  before={$beforeJson}  ->  after={$afterJson}\n";

        if (! $dryRun) {
            $items['handwritten_notes'] = $newNotes;
            $r->receipt_items = $items;
            $r->save();
        }
    }
});

echo "\nDone.\n";
echo "  scanned : {$scanned}\n";
echo "  changed : {$changed}\n";
echo "  notes added (empty -> non-empty): {$noteAdded}\n";
echo "  skipped (ONLY_NEW had notes)   : {$skipped}\n";
echo "  failed  : {$failed}\n";
