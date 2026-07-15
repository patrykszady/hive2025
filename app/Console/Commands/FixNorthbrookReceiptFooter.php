<?php

namespace App\Console\Commands;

use App\Models\Expense;
use App\Models\ExpenseReceipts;
use App\Models\Receipt;
use Illuminate\Console\Command;

/**
 * Repair Village of Northbrook (BS&A → Stripe) receipts that OCR'd with the
 * Stripe email footer attached:
 *
 *  - the footer text "…provide invoicing and payment processing" made the
 *    invoice fallback regex capture "oicing" instead of the "Receipt #…";
 *  - receipt_html / raw_content stored the footer junk after "Amount paid".
 *
 * Sweeps ALL Northbrook (vendor 425) expenses: trims stored receipt content at
 * the footer markers and fixes the expense invoice from the receipt's
 * "Receipt #" line when the invoice is missing or the bogus "oicing".
 *
 * Also adds the `ocr_content_end` markers to the BS&A receipt config so future
 * receipts are trimmed at OCR time (see ReceiptController::extractReceipt).
 *
 * Idempotent; dry-run by default, pass --apply to execute.
 */
class FixNorthbrookReceiptFooter extends Command
{
    protected $signature = 'app:fix-northbrook-receipt-footer {--apply : Actually write the changes}';

    protected $description = 'Fix Northbrook expenses hit by the Stripe footer: invoice from Receipt #, trim stored receipts, add ocr_content_end to the BS&A config.';

    private const VENDOR_ID = 425; // Village of Northbrook
    private const BAD_INVOICE = 'oicing';

    /** Markers where the receipt body ends and the Stripe footer begins. */
    private const OCR_CONTENT_END = [
        '[](https://www.northbrook.il.us',
        'If you have any questions, contact us',
        'Something wrong with the email?',
    ];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $this->info($apply ? 'APPLY mode — writing changes.' : 'DRY-RUN — pass --apply to write changes.');

        $this->fixReceiptConfig($apply);
        $this->sweepExpenses($apply);

        return self::SUCCESS;
    }

    private function fixReceiptConfig(bool $apply): void
    {
        $config = Receipt::where('from_address', 'noreply@bsaonline.com')
            ->where('vendor_id', self::VENDOR_ID)
            ->first();

        if (! $config) {
            $this->warn('Receipt config for noreply@bsaonline.com (vendor '.self::VENDOR_ID.') not found — skipping config update.');

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

    private function sweepExpenses(bool $apply): void
    {
        $expenses = Expense::withoutGlobalScopes()
            ->where('vendor_id', self::VENDOR_ID)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get();

        foreach ($expenses as $expense) {
            $records = ExpenseReceipts::where('expense_id', $expense->id)->get();
            $receiptNumber = null;

            foreach ($records as $record) {
                $htmlTrimmed = $this->trimAtMarkers((string) $record->receipt_html);
                $items = $record->receipt_items ?? [];
                $rawTrimmed = $this->trimAtMarkers((string) ($items['raw_content'] ?? ''));

                // Receipt # from the (trimmed or stored) content — used for the
                // expense invoice and the receipt's own invoice_number.
                if (preg_match('/Receipt\s*#([A-Za-z0-9\-]+)/', $htmlTrimmed ?? (string) $record->receipt_html, $m)) {
                    $receiptNumber = $m[1];
                }

                $changes = [];
                if ($htmlTrimmed !== null) {
                    $changes[] = 'receipt_html '.strlen((string) $record->receipt_html).' → '.strlen($htmlTrimmed).' chars';
                }
                if ($rawTrimmed !== null) {
                    $changes[] = 'raw_content '.strlen((string) ($items['raw_content'] ?? '')).' → '.strlen($rawTrimmed).' chars';
                }
                if ($receiptNumber && empty($items['invoice_number'])) {
                    $changes[] = 'invoice_number → '.$receiptNumber;
                }

                if (empty($changes)) {
                    continue;
                }

                $this->line("Expense {$expense->id} / receipt {$record->id}: ".implode(', ', $changes).'.');
                if ($apply) {
                    if ($htmlTrimmed !== null) {
                        $record->receipt_html = $htmlTrimmed;
                    }
                    if ($rawTrimmed !== null) {
                        $items['raw_content'] = $rawTrimmed;
                    }
                    if ($receiptNumber && empty($items['invoice_number'])) {
                        $items['invoice_number'] = $receiptNumber;
                    }
                    $record->receipt_items = $items;
                    $record->save();
                    $this->info('  → saved.');
                }
            }

            // Fix the expense invoice from the receipt number when missing/bogus
            $current = trim((string) $expense->invoice);
            if ($receiptNumber && ($current === self::BAD_INVOICE || $current === '')) {
                $this->line("Expense {$expense->id}: invoice [{$current}] → [{$receiptNumber}].");
                if ($apply) {
                    $expense->invoice = $receiptNumber;
                    $expense->save();
                    $expense->searchable();
                    $this->info('  → saved.');
                }
            } elseif ($receiptNumber && $current !== $receiptNumber) {
                $this->warn("Expense {$expense->id}: invoice [{$current}] differs from receipt # [{$receiptNumber}] — not touching it.");
            }
        }
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
