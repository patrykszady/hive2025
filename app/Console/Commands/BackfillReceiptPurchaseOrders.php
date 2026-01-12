<?php

namespace App\Console\Commands;

use App\Models\ExpenseReceipts;
use Illuminate\Console\Command;

class BackfillReceiptPurchaseOrders extends Command
{
    protected $signature = 'receipts:backfill-po {--dry-run : Show what would be updated without making changes}';

    protected $description = 'Backfill purchase orders from receipt HTML content using PO regex patterns';

    /**
     * The regex pattern for extracting purchase orders from receipt content.
     * Matches: PO/JOB NAME, PO NUMBER, PO #, P.O. #, JOB NAME, PRO JobName
     */
    protected string $poRegex = '/(?:PO\s*\/\s*JOB\s*NAME|PO\s*NUMBER|PO\s*#|P\.?O\.?\s*#?|JOB\s*NAME|PRO\s*JobName)\s*:\s*([^\r\n]{1,80})/i';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('🔍 DRY RUN - No changes will be made');
        }

        $this->info('🔎 Finding receipts with missing purchase orders (only unmatched expenses)...');

        $receipts = ExpenseReceipts::with(['expense.vendor'])
            ->whereNotNull('receipt_html')
            ->where('receipt_html', '!=', '')
            ->where(function ($query) {
                $query->whereRaw("JSON_TYPE(JSON_EXTRACT(receipt_items, '$.purchase_order')) = 'NULL'")
                    ->orWhereRaw("JSON_EXTRACT(receipt_items, '$.purchase_order') = ''")
                    ->orWhereRaw("JSON_EXTRACT(receipt_items, '$.purchase_order') = 'null'");
            })
            ->whereHas('expense', function ($query) {
                $query->where(function ($q) {
                    $q->whereNull('project_id')
                      ->orWhere('project_id', 0);
                })
                ->whereNull('distribution_id');
            })
            ->get();

        $this->info("📋 Found {$receipts->count()} receipts to process");

        $updated = 0;
        $skipped = 0;
        $results = [];

        $bar = $this->output->createProgressBar($receipts->count());
        $bar->start();

        foreach ($receipts as $receipt) {
            $po = $this->extractPurchaseOrder($receipt->receipt_html);

            if ($po !== null) {
                if (!$dryRun) {
                    $items = $receipt->receipt_items ?? [];
                    $items['purchase_order'] = $po;
                    $receipt->receipt_items = $items;
                    $receipt->save();
                }

                $updated++;
                $results[] = [
                    'expense_id' => $receipt->expense_id,
                    'date' => $receipt->expense?->date?->format('Y-m-d') ?? '-',
                    'vendor' => substr($receipt->expense?->vendor?->business_name ?? '-', 0, 20),
                    'amount' => '$' . number_format((float) ($receipt->expense?->amount ?? 0), 2),
                    'po' => $po,
                ];
            } else {
                $skipped++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        if ($dryRun && !empty($results)) {
            $this->info("📋 Purchase orders found:");
            $this->table(['Expense ID', 'Date', 'Vendor', 'Amount', 'PO'], $results);
        }

        $this->info("🎉 Backfill complete!");
        $this->table(
            ['Metric', 'Count'],
            [
                ['Receipts processed', $receipts->count()],
                ['Purchase orders ' . ($dryRun ? 'found' : 'updated'), $updated],
                ['Skipped (no PO found)', $skipped],
            ]
        );

        if ($dryRun && $updated > 0) {
            $this->warn("Run without --dry-run to apply changes");
        }

        return self::SUCCESS;
    }

    private function extractPurchaseOrder(string $content): ?string
    {
        if (preg_match($this->poRegex, $content, $matches)) {
            $candidate = trim($matches[1] ?? '');

            // Clean common trailing fragments (multiple spaces followed by anything)
            $candidate = preg_replace('/\s{2,}.*/', '', $candidate) ?? $candidate;

            if ($candidate !== '' && $this->isValidPurchaseOrder($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function isValidPurchaseOrder(string $po): bool
    {
        // Reject single characters
        if (strlen($po) <= 1) {
            return false;
        }

        // Reject common junk/placeholder values
        $invalidValues = [
            '0', '00', '000',
            'o', 'oo', 'ooo',
            'na', 'n/a', 'none', 'no',
            '.', '|', '-',
            'greg', 'gregory',
        ];

        if (in_array(strtolower(trim($po)), $invalidValues, true)) {
            return false;
        }

        // Reject if it's only zeros and dots
        if (preg_match('/^[0.]+$/', $po)) {
            return false;
        }

        // Reject patterns like "Gregory  Job Name:" (incomplete extraction)
        if (preg_match('/job\s*name/i', $po)) {
            return false;
        }

        return true;
    }
}
