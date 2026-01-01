<?php

namespace App\Console\Commands;

use App\Models\ExpenseReceipts;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ReceiptsPurgeDuplicates extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'receipts:purge-duplicates
        {--execute : Actually delete records (default is dry-run)}
        {--expense= : Only process a single expense_id}
        {--limit-expenses=0 : Max distinct expense_ids to scan (0 = unlimited)}
        {--delete-limit=0 : Max duplicate receipts to delete (0 = unlimited)}
        {--delete-files : Also delete receipt PDFs from storage for deleted rows}
    ';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Detect and purge duplicate expense receipts using normalized HTML/line-item comparison (dry-run by default).';

    public function handle(): int
    {
        $execute = (bool) $this->option('execute');
        $deleteFiles = (bool) $this->option('delete-files');
        $expenseId = $this->option('expense');
        $limitExpenses = (int) $this->option('limit-expenses');
        $deleteLimit = (int) $this->option('delete-limit');

        if ($limitExpenses < 0 || $deleteLimit < 0) {
            $this->error('Options --limit-expenses and --delete-limit must be >= 0.');
            return self::FAILURE;
        }

        if (is_string($expenseId) && trim($expenseId) !== '' && (int) $expenseId <= 0) {
            $this->error('Option --expense must be a valid expense_id.');
            return self::FAILURE;
        }

        $this->info($execute ? 'Executing duplicate receipt purge...' : 'Dry-run (no deletes).');
        if ($deleteFiles) {
            $this->info('Storage file deletion is enabled (--delete-files).');
        }

        $expenseIdsQuery = ExpenseReceipts::query()
            ->select('expense_id')
            ->when(is_string($expenseId) && trim($expenseId) !== '', function ($query) use ($expenseId) {
                $query->where('expense_id', (int) $expenseId);
            })
            ->groupBy('expense_id')
            ->orderBy('expense_id');

        if ($limitExpenses > 0) {
            $expenseIdsQuery->limit($limitExpenses);
        }

        $expenseIds = $expenseIdsQuery->pluck('expense_id');

        if ($expenseIds->isEmpty()) {
            $this->info('No receipts found to scan.');
            return self::SUCCESS;
        }

        $deleted = 0;
        $scannedReceipts = 0;
        $matchedDuplicates = 0;

        foreach ($expenseIds as $eid) {
            if ($deleteLimit > 0 && $deleted >= $deleteLimit) {
                break;
            }

            $receipts = ExpenseReceipts::query()
                ->where('expense_id', (int) $eid)
                ->orderBy('id')
                ->get(['id', 'expense_id', 'receipt_filename', 'receipt_html', 'receipt_items']);

            if ($receipts->count() <= 1) {
                continue;
            }

            $seenHtmlHashes = [];
            $seenLineItemHashes = [];
            // Track if we've kept a receipt with handwritten notes for each hash
            $seenLineItemHashesWithNotes = [];

            foreach ($receipts as $receipt) {
                $scannedReceipts++;

                $htmlHash = ExpenseReceipts::receiptHtmlHash($receipt->receipt_html);
                $lineItemsHash = ExpenseReceipts::receiptLineItemsHash($receipt->receipt_items ?? []);

                // Check if this receipt has handwritten notes
                $items = $receipt->receipt_items ?? [];
                $notes = $items['handwritten_notes'] ?? [];
                $hasNotes = is_array($notes) && count(array_filter($notes, fn($n) => is_string($n) && trim($n) !== '')) > 0;

                $isDuplicate = false;

                if ($lineItemsHash !== null && isset($seenLineItemHashes[$lineItemsHash])) {
                    // We've seen this hash before. It's a duplicate UNLESS:
                    // - This receipt has notes AND we haven't kept one with notes yet
                    if ($hasNotes && !isset($seenLineItemHashesWithNotes[$lineItemsHash])) {
                        // Keep this one too - it has notes that we want to preserve
                        $seenLineItemHashesWithNotes[$lineItemsHash] = $receipt->id;
                    } else {
                        $isDuplicate = true;
                    }
                }

                if (! $isDuplicate && $htmlHash !== '' && isset($seenHtmlHashes[$htmlHash])) {
                    $isDuplicate = true;
                }

                if (! $isDuplicate) {
                    if ($lineItemsHash !== null) {
                        $seenLineItemHashes[$lineItemsHash] = $receipt->id;
                        if ($hasNotes) {
                            $seenLineItemHashesWithNotes[$lineItemsHash] = $receipt->id;
                        }
                    }
                    if ($htmlHash !== '') {
                        $seenHtmlHashes[$htmlHash] = $receipt->id;
                    }
                    continue;
                }

                $matchedDuplicates++;

                $this->line("Duplicate: expense_id={$receipt->expense_id} receipt_id={$receipt->id} filename=".($receipt->receipt_filename ?? 'null'));

                if (! $execute) {
                    continue;
                }

                DB::transaction(function () use ($receipt, $deleteFiles): void {
                    $filename = $receipt->receipt_filename;

                    $receipt->delete();

                    if ($deleteFiles && is_string($filename) && $filename !== '') {
                        $path = 'receipts/' . $filename;
                        if (Storage::disk('files')->exists($path)) {
                            Storage::disk('files')->delete($path);
                        }
                    }
                });

                $deleted++;

                if ($deleteLimit > 0 && $deleted >= $deleteLimit) {
                    break;
                }
            }
        }

        $this->info("Scanned receipts: {$scannedReceipts}");
        $this->info("Matched duplicates: {$matchedDuplicates}");
        $this->info($execute ? "Deleted: {$deleted}" : 'Deleted: 0 (dry-run)');

        if (! $execute && $matchedDuplicates > 0) {
            $this->warn('Re-run with --execute to delete these duplicates.');
        }

        return self::SUCCESS;
    }
}
