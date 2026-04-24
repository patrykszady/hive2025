<?php

namespace App\Console\Commands;

use App\Models\ExpenseReceipts;
use Illuminate\Console\Command;

class BackfillFloorDecorInvoiceNumbers extends Command
{
    protected $signature = 'receipts:backfill-floor-decor-invoice
                            {--dry-run : Show what would be updated without making changes}';

    protected $description = 'Backfill Floor & Decor invoice numbers from raw_content Transaction Number field';

    /**
     * Matches "Transaction Number\n\n1013601611501618" style lines in F&D raw OCR content.
     */
    protected string $transactionRegex = '/Transaction\s+Number\s*\n+([\d]{10,20})/i';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN - No changes will be made');
        }

        $receipts = ExpenseReceipts::query()
            ->with('expense:id,invoice')
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(receipt_items, '$.merchant_name')) LIKE '%Floor%Decor%'")
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(receipt_items, '$.raw_content')) IS NOT NULL")
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(receipt_items, '$.raw_content')) != 'null'")
            ->get();

        $this->info("Found {$receipts->count()} Floor & Decor receipts with raw_content");

        $updated = 0;
        $skipped = 0;

        foreach ($receipts as $receipt) {
            $rawContent = $receipt->receipt_items['raw_content'] ?? '';
            $currentInvoice = $receipt->receipt_items['invoice_number'] ?? null;
            $currentExpenseInvoice = $receipt->expense?->invoice;

            if (!preg_match($this->transactionRegex, $rawContent, $match)) {
                $skipped++;
                continue;
            }

            $transactionNumber = trim($match[1]);

            if ($currentInvoice === $transactionNumber && $currentExpenseInvoice === $transactionNumber) {
                $skipped++;
                continue;
            }

            $this->line(sprintf(
                '  Receipt %d (expense %d): receipt "%s" → "%s", expense "%s" → "%s"',
                $receipt->id,
                $receipt->expense_id,
                $currentInvoice ?? 'null',
                $transactionNumber,
                $currentExpenseInvoice ?? 'null',
                $transactionNumber
            ));

            if (!$dryRun) {
                $items = $receipt->receipt_items ?? [];
                $items['invoice_number'] = $transactionNumber;
                $receipt->receipt_items = $items;
                $receipt->save();

                if ($receipt->expense !== null) {
                    $receipt->expense->invoice = $transactionNumber;
                    $receipt->expense->save();
                }
            }

            $updated++;
        }

        $this->info("Updated: {$updated}, Skipped: {$skipped}");

        return self::SUCCESS;
    }
}
