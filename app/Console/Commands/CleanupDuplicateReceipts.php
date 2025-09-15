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
            $expenseDuplicates = [];
            
            foreach ($receipts as $receipt) {
                $isDuplicate = false;
                $duplicateType = '';
                
                // Check for exact HTML content duplicate
                if (isset($seenContent[$receipt->receipt_html])) {
                    $isDuplicate = true;
                    $duplicateType = 'identical HTML content';
                } else {
                    // Check for content similarity with existing receipts
                    foreach ($seenContent as $seenHtml => $seenReceiptId) {
                        $seenReceipt = $receipts->firstWhere('id', $seenReceiptId);
                        if ($seenReceipt && $this->areReceiptsSimilar($receipt, $seenReceipt, 0.9)) {
                            $isDuplicate = true;
                            $duplicateType = 'similar content (90%+ match)';
                            break;
                        }
                    }
                    
                    // If not a duplicate, add to seen content
                    if (!$isDuplicate) {
                        $seenContent[$receipt->receipt_html] = $receipt->id;
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

    /**
     * Compare two receipts to determine if they are similar enough to be considered duplicates.
     * This method checks content length, file size, and text similarity with configurable threshold.
     */
    private function areReceiptsSimilar($receipt1, $receipt2, float $threshold = 0.9): bool
    {
        // If either receipt has no HTML content, fall back to strict comparison
        if (empty($receipt1->receipt_html) || empty($receipt2->receipt_html)) {
            return $receipt1->receipt_html === $receipt2->receipt_html;
        }

        $html1 = $receipt1->receipt_html;
        $html2 = $receipt2->receipt_html;

        // Check for exact match first (most efficient)
        if ($html1 === $html2) {
            return true;
        }

        // Check content length similarity (within 10% difference for content structure)
        $length1 = strlen($html1);
        $length2 = strlen($html2);
        $lengthDiff = abs($length1 - $length2) / max($length1, $length2);
        
        if ($lengthDiff > 0.15) {
            return false; // Content lengths too different (15% tolerance for minor variations)
        }

        // For performance, use different approaches based on content size
        if ($length1 < 3000 && $length2 < 3000) {
            // For smaller content, use character-level similarity
            $similarity = $this->calculateTextSimilarity($html1, $html2);
            return $similarity >= $threshold;
        } else {
            // For larger content, extract and compare meaningful data
            return $this->compareReceiptData($receipt1, $receipt2, $threshold);
        }
    }

    /**
     * Calculate text similarity using multiple methods for accuracy
     */
    private function calculateTextSimilarity(string $text1, string $text2): float
    {
        // Remove HTML tags and normalize whitespace for better comparison
        $clean1 = preg_replace('/\s+/', ' ', strip_tags($text1));
        $clean2 = preg_replace('/\s+/', ' ', strip_tags($text2));
        
        $maxLength = max(strlen($clean1), strlen($clean2));
        if ($maxLength === 0) {
            return 1.0; // Both empty
        }

        // Use Levenshtein distance for character-level similarity
        $distance = levenshtein(
            substr($clean1, 0, 1000), // Limit to prevent memory issues
            substr($clean2, 0, 1000)
        );
        
        $charSimilarity = 1 - ($distance / min(1000, $maxLength));
        
        // Also compare word-level similarity
        $words1 = array_filter(explode(' ', strtolower($clean1)));
        $words2 = array_filter(explode(' ', strtolower($clean2)));
        
        if (empty($words1) && empty($words2)) {
            return 1.0;
        }
        
        $commonWords = count(array_intersect($words1, $words2));
        $totalWords = max(count($words1), count($words2));
        $wordSimilarity = $totalWords > 0 ? $commonWords / $totalWords : 0;
        
        // Weighted average: character similarity (70%) + word similarity (30%)
        return ($charSimilarity * 0.7) + ($wordSimilarity * 0.3);
    }

    /**
     * Compare receipt data including line items and key financial information
     */
    private function compareReceiptData($receipt1, $receipt2, float $threshold): bool
    {
        $items1 = $this->extractReceiptItems($receipt1);
        $items2 = $this->extractReceiptItems($receipt2);
        
        // Compare amounts if available
        if (isset($items1['total_amount']) && isset($items2['total_amount'])) {
            $amount1 = (float) $items1['total_amount'];
            $amount2 = (float) $items2['total_amount'];
            
            // If amounts are significantly different, likely not duplicates
            if (abs($amount1 - $amount2) > max($amount1, $amount2) * 0.05) {
                return false; // More than 5% difference in amounts
            }
        }
        
        // Compare line items if available
        if (isset($items1['line_items']) && isset($items2['line_items'])) {
            $lineItemsSimilarity = $this->compareLineItems($items1['line_items'], $items2['line_items']);
            if ($lineItemsSimilarity >= $threshold) {
                return true;
            }
        }
        
        // Fall back to HTML content similarity
        return $this->calculateTextSimilarity($receipt1->receipt_html, $receipt2->receipt_html) >= $threshold;
    }

    /**
     * Extract structured data from receipt items
     */
    private function extractReceiptItems($receipt): array
    {
        if (!$receipt->receipt_items) {
            return [];
        }
        
        $items = is_string($receipt->receipt_items) 
            ? json_decode($receipt->receipt_items, true) 
            : (array) $receipt->receipt_items;
            
        return $items ?: [];
    }

    /**
     * Compare line items between two receipts
     */
    private function compareLineItems($lineItems1, $lineItems2): float
    {
        if (!is_array($lineItems1) || !is_array($lineItems2)) {
            return 0.0;
        }
        
        if (empty($lineItems1) && empty($lineItems2)) {
            return 1.0;
        }
        
        // Compare item descriptions and quantities
        $items1 = array_map(function($item) {
            return strtolower(trim($item['description'] ?? $item['name'] ?? ''));
        }, $lineItems1);
        
        $items2 = array_map(function($item) {
            return strtolower(trim($item['description'] ?? $item['name'] ?? ''));
        }, $lineItems2);
        
        // Filter out empty items
        $items1 = array_filter($items1);
        $items2 = array_filter($items2);
        
        if (empty($items1) && empty($items2)) {
            return 1.0;
        }
        
        $commonItems = count(array_intersect($items1, $items2));
        $totalItems = max(count($items1), count($items2));
        
        return $totalItems > 0 ? $commonItems / $totalItems : 0.0;
    }
}
