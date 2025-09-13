<?php

namespace App\Console\Commands;

use App\Models\ExpenseReceipts;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class CleanupDuplicateReceipts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'receipts:cleanup-duplicates {--dry-run : Show what would be deleted without actually deleting}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Remove duplicate expense receipts based on HTML content and invoice numbers, keeping the earliest created record';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');
        
        if ($isDryRun) {
            $this->info('🔍 Running in DRY RUN mode - no changes will be made');
        } else {
            $this->warn('⚠️  Running in LIVE mode - duplicates will be permanently deleted');
            if (!$this->confirm('Are you sure you want to proceed?')) {
                $this->info('Operation cancelled.');
                return 0;
            }
        }
        
        $this->info('🔎 Scanning for duplicate receipts...');
        
        $duplicatesFound = 0;
        $duplicatesRemoved = 0;
        $filesDeleted = 0;
        $affectedExpenses = [];
        
        // Group receipts by expense_id for efficient processing
        $expenses = ExpenseReceipts::select('expense_id')
            ->distinct()
            ->orderBy('expense_id')
            ->get();
            
        $this->withProgressBar($expenses, function ($expense) use (&$duplicatesFound, &$duplicatesRemoved, &$filesDeleted, &$affectedExpenses, $isDryRun) {
            $receipts = ExpenseReceipts::where('expense_id', $expense->expense_id)
                ->orderBy('created_at', 'asc')
                ->get();
                
            if ($receipts->count() <= 1) {
                return; // Skip expenses with only one receipt
            }
            
            $seenContent = [];
            $seenInvoices = [];
            $expenseDuplicates = [];
            
            foreach ($receipts as $receipt) {
                $isDuplicate = false;
                $duplicateType = '';
                
                // Check for HTML content duplicate
                if (isset($seenContent[$receipt->receipt_html])) {
                    $isDuplicate = true;
                    $duplicateType = 'HTML content';
                } else {
                    $seenContent[$receipt->receipt_html] = $receipt->id;
                }
                
                // Check for invoice number duplicate
                if (!$isDuplicate && $receipt->receipt_items) {
                    $items = is_string($receipt->receipt_items) 
                        ? json_decode($receipt->receipt_items, true) 
                        : (array) $receipt->receipt_items;
                        
                    if (isset($items['invoice_number']) && !empty($items['invoice_number'])) {
                        $invoiceKey = $expense->expense_id . '_' . $items['invoice_number'];
                        if (isset($seenInvoices[$invoiceKey])) {
                            $isDuplicate = true;
                            $duplicateType = 'invoice number (' . $items['invoice_number'] . ')';
                        } else {
                            $seenInvoices[$invoiceKey] = $receipt->id;
                        }
                    }
                }
                
                if ($isDuplicate) {
                    $duplicatesFound++;
                    $expenseDuplicates[] = [
                        'receipt_id' => $receipt->id,
                        'filename' => $receipt->receipt_filename,
                        'type' => $duplicateType,
                        'created_at' => $receipt->created_at
                    ];
                    
                    if (!$isDryRun) {
                        // Delete the receipt file if it exists
                        $filePath = 'receipts/' . $receipt->receipt_filename;
                        if (Storage::disk('files')->exists($filePath)) {
                            Storage::disk('files')->delete($filePath);
                            $filesDeleted++;
                        }
                        
                        // Delete the database record
                        $receipt->delete();
                        $duplicatesRemoved++;
                    }
                }
            }
            
            // Track expenses that had duplicates
            if (!empty($expenseDuplicates)) {
                $affectedExpenses[$expense->expense_id] = $expenseDuplicates;
            }
        });
        
        $this->newLine(2);
        
        if ($isDryRun) {
            $this->info("📊 DRY RUN RESULTS:");
            $this->info("   Found {$duplicatesFound} duplicate receipts that would be removed");
        } else {
            $this->info("✅ CLEANUP COMPLETED:");
            $this->info("   Removed {$duplicatesRemoved} duplicate receipt records");
            $this->info("   Deleted {$filesDeleted} duplicate receipt files");
        }
        
        // Show detailed breakdown of affected expenses
        if (!empty($affectedExpenses)) {
            $this->newLine();
            $this->info("📋 AFFECTED EXPENSES:");
            foreach ($affectedExpenses as $expenseId => $duplicates) {
                $this->line("   Expense ID {$expenseId}: " . count($duplicates) . " duplicate(s) " . ($isDryRun ? "found" : "removed"));
                foreach ($duplicates as $duplicate) {
                    $this->line("      - Receipt ID {$duplicate['receipt_id']} ({$duplicate['filename']}) - duplicate by {$duplicate['type']}");
                }
            }
        }
        
        if ($duplicatesFound === 0) {
            $this->info("🎉 No duplicate receipts found - your data is clean!");
        }
        
        return 0;
    }
}
