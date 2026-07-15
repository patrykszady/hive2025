<?php

namespace App\Console\Commands;

use App\Models\Expense;
use App\Models\ExpenseReceipts;
use App\Models\Receipt;
use Illuminate\Console\Command;

/**
 * Floor & Decor e-receipts are OCR'd from the receipt image, which includes
 * everything below the totals: the "Low Prices, Everyday" slogan, barcode,
 * the full return-policy boilerplate, "THANK YOU FOR SHOPPING" and a QR code.
 *
 * Adds `ocr_content_end` markers to receipt config 14 so future receipts are
 * trimmed at OCR time (ReceiptController::extractReceipt), and backfills the
 * trim on already-stored receipt_html / raw_content for vendor 13 expenses.
 *
 * Idempotent; dry-run by default, pass --apply to execute.
 */
class FixFloorDecorReceiptFooter extends Command
{
    protected $signature = 'app:fix-floor-decor-receipt-footer {--apply : Actually write the changes}';

    protected $description = 'Add ocr_content_end to the Floor & Decor receipt config and trim the slogan/return-policy footer from stored receipts.';

    private const VENDOR_ID = 13; // Floor & Decor
    private const FROM_ADDRESS = 'auto-confirm@email.flooranddecor.com';

    /**
     * Markers where the receipt body ends and the footer begins. Matched
     * against raw OCR content (pre-htmlspecialchars), so no entities — avoid
     * "&" in markers. First marker found wins.
     */
    private const OCR_CONTENT_END = [
        'Low Prices, Everyday',
        'THANK YOU FOR SHOPPING',
    ];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $this->info($apply ? 'APPLY mode — writing changes.' : 'DRY-RUN — pass --apply to write changes.');

        $this->fixReceiptConfig($apply);
        $this->trimStoredReceipts($apply);

        return self::SUCCESS;
    }

    private function fixReceiptConfig(bool $apply): void
    {
        $config = Receipt::where('from_address', self::FROM_ADDRESS)
            ->where('vendor_id', self::VENDOR_ID)
            ->first();

        if (! $config) {
            $this->warn('Receipt config for '.self::FROM_ADDRESS.' (vendor '.self::VENDOR_ID.') not found — skipping config update.');

            return;
        }

        $options = $config->options ?? [];
        if (! empty($options['ocr_content_end'])) {
            $this->line("Receipt config {$config->id}: ocr_content_end already set — OK.");

            return;
        }

        $this->line("Receipt config {$config->id}: add ocr_content_end (".count(self::OCR_CONTENT_END).' markers).');
        if ($apply) {
            $options['ocr_content_end'] = self::OCR_CONTENT_END;
            $config->options = $options;
            $config->save();
            $this->info('  → saved.');
        }
    }

    private function trimStoredReceipts(bool $apply): void
    {
        $expenseIds = Expense::withoutGlobalScopes()
            ->where('vendor_id', self::VENDOR_ID)
            ->pluck('id');

        $trimmed = 0;
        $untouched = 0;

        ExpenseReceipts::whereIn('expense_id', $expenseIds)
            ->orderBy('id')
            ->chunkById(100, function ($records) use ($apply, &$trimmed, &$untouched) {
                foreach ($records as $record) {
                    $htmlTrimmed = $this->trimAtMarkers((string) $record->receipt_html);
                    $items = $record->receipt_items ?? [];
                    $rawTrimmed = $this->trimAtMarkers((string) ($items['raw_content'] ?? ''));

                    if ($htmlTrimmed === null && $rawTrimmed === null) {
                        $untouched++;

                        continue;
                    }

                    $changes = [];
                    if ($htmlTrimmed !== null) {
                        $changes[] = 'receipt_html '.strlen((string) $record->receipt_html).' → '.strlen($htmlTrimmed).' chars';
                    }
                    if ($rawTrimmed !== null) {
                        $changes[] = 'raw_content '.strlen((string) ($items['raw_content'] ?? '')).' → '.strlen($rawTrimmed).' chars';
                    }

                    $this->line("Receipt {$record->id} (expense {$record->expense_id}): ".implode(', ', $changes).'.');
                    $trimmed++;

                    if ($apply) {
                        if ($htmlTrimmed !== null) {
                            $record->receipt_html = $htmlTrimmed;
                        }
                        if ($rawTrimmed !== null) {
                            $items['raw_content'] = $rawTrimmed;
                            $record->receipt_items = $items;
                        }
                        $record->save();
                    }
                }
            });

        $this->newLine();
        $this->info(($apply ? 'Trimmed' : 'Would trim')." {$trimmed} receipts; {$untouched} already clean.");
    }

    /** Returns the trimmed string, or null when no marker is present (nothing to do). */
    private function trimAtMarkers(string $content): ?string
    {
        foreach (self::OCR_CONTENT_END as $marker) {
            $pos = strpos($content, $marker);
            if ($pos !== false) {
                return rtrim(substr($content, 0, $pos));
            }
        }

        return null;
    }
}
