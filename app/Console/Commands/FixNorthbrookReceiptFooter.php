<?php

namespace App\Console\Commands;

use App\Models\Expense;
use App\Models\ExpenseReceipts;
use App\Models\Receipt;
use Illuminate\Console\Command;

/**
 * One-time repair for the Village of Northbrook (BS&A → Stripe) receipt that
 * OCR'd with the Stripe email footer attached:
 *
 *  - the footer text "…provide invoicing and payment processing" made the
 *    invoice fallback regex capture "oicing" instead of "Receipt #1134-0898";
 *  - receipt_html / raw_content stored the footer junk after "Amount paid $70.00".
 *
 * Also adds the new `ocr_content_end` markers to the BS&A receipt config so
 * future receipts are trimmed at OCR time (see ReceiptController::extractReceipt).
 *
 * Idempotent; dry-run by default, pass --apply to execute.
 */
class FixNorthbrookReceiptFooter extends Command
{
    protected $signature = 'app:fix-northbrook-receipt-footer {--apply : Actually write the changes}';

    protected $description = 'Fix expense 27226 invoice (oicing → 1134-0898), trim the Stripe footer from its stored receipt, and add ocr_content_end to the BS&A receipt config.';

    private const EXPENSE_ID = 27226;
    private const VENDOR_ID = 425; // Village of Northbrook
    private const CORRECT_INVOICE = '1134-0898';
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
        $this->fixExpenseInvoice($apply);
        $this->trimStoredReceipt($apply);

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

    private function fixExpenseInvoice(bool $apply): void
    {
        $expense = Expense::withoutGlobalScopes()->find(self::EXPENSE_ID);

        if (! $expense || (int) $expense->vendor_id !== self::VENDOR_ID || (float) $expense->amount !== 70.00) {
            $this->warn('Expense '.self::EXPENSE_ID.' not found or does not look like the Northbrook $70.00 expense — skipping invoice fix.');

            return;
        }

        $current = trim((string) $expense->invoice);
        if ($current === self::CORRECT_INVOICE) {
            $this->line('Expense '.self::EXPENSE_ID.': invoice already correct — OK.');

            return;
        }

        if ($current !== self::BAD_INVOICE && $current !== '') {
            $this->warn('Expense '.self::EXPENSE_ID.": invoice is [{$current}] (expected [".self::BAD_INVOICE.']) — not touching it.');

            return;
        }

        $this->line('Expense '.self::EXPENSE_ID.": invoice [{$current}] → [".self::CORRECT_INVOICE.'].');
        if ($apply) {
            $expense->invoice = self::CORRECT_INVOICE;
            $expense->save();
            $expense->searchable();
            $this->info('  → saved.');
        }
    }

    private function trimStoredReceipt(bool $apply): void
    {
        $records = ExpenseReceipts::where('expense_id', self::EXPENSE_ID)->get();

        if ($records->isEmpty()) {
            $this->warn('No receipt records for expense '.self::EXPENSE_ID.' — skipping trim.');

            return;
        }

        foreach ($records as $record) {
            $htmlTrimmed = $this->trimAtMarkers((string) $record->receipt_html);
            $items = $record->receipt_items ?? [];
            $rawTrimmed = $this->trimAtMarkers((string) ($items['raw_content'] ?? ''));

            $changes = [];
            if ($htmlTrimmed !== null) {
                $changes[] = 'receipt_html '.strlen((string) $record->receipt_html).' → '.strlen($htmlTrimmed).' chars';
            }
            if ($rawTrimmed !== null) {
                $changes[] = 'raw_content '.strlen((string) ($items['raw_content'] ?? '')).' → '.strlen($rawTrimmed).' chars';
            }
            if (empty($items['invoice_number'])) {
                $changes[] = 'invoice_number → '.self::CORRECT_INVOICE;
            }

            if (empty($changes)) {
                $this->line("Receipt {$record->id}: already trimmed — OK.");

                continue;
            }

            $this->line("Receipt {$record->id}: ".implode(', ', $changes).'.');
            if ($apply) {
                if ($htmlTrimmed !== null) {
                    $record->receipt_html = $htmlTrimmed;
                }
                if ($rawTrimmed !== null) {
                    $items['raw_content'] = $rawTrimmed;
                }
                if (empty($items['invoice_number'])) {
                    $items['invoice_number'] = self::CORRECT_INVOICE;
                }
                $record->receipt_items = $items;
                $record->save();
                $this->info('  → saved.');
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
