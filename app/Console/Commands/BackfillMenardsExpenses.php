<?php

namespace App\Console\Commands;

use App\Http\Controllers\ReceiptController;
use App\Models\Expense;
use App\Models\ExpenseReceipts;
use App\Models\Vendor;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class BackfillMenardsExpenses extends Command
{
    protected $signature = 'menards:backfill-expenses
        {--belongs-to-vendor-id=1 : The belongs_to_vendor_id for created expenses}
        {--vendor-id= : Menards vendor ID (auto-detected if omitted)}
        {--dry-run : Show what would be created without actually creating}';

    protected $description = 'Create expenses for orphaned Menards receipt files that have no linked expense';

    public function handle(): int
    {
        $belongsToVendorId = (int) $this->option('belongs-to-vendor-id');
        $vendorId = $this->option('vendor-id') ?: $this->findMenardsVendorId();

        if (! $vendorId) {
            $this->error('Could not determine Menards vendor_id. Pass --vendor-id=N explicitly.');

            return self::FAILURE;
        }

        $dryRun = $this->option('dry-run');

        $this->info("Menards vendor_id: {$vendorId}, belongs_to_vendor_id: {$belongsToVendorId}");
        if ($dryRun) {
            $this->warn('DRY RUN — no changes will be made.');
        }

        // Find orphaned menards receipt files (no expense ID prefix)
        $allFiles = Storage::disk('files')->files('receipts');
        $orphaned = [];

        foreach ($allFiles as $filePath) {
            $filename = basename($filePath);

            // Orphaned files: "menards-YYYY-MM-DD-amount.ext" (no numeric ID prefix)
            if (preg_match('/^menards-(\d{4}-\d{2}-\d{2})-(.+)\.(pdf|jpg|png)$/i', $filename, $matches)) {
                // Confirm no ExpenseReceipts record exists for this file
                $existing = ExpenseReceipts::where('receipt_filename', $filename)->first();
                if ($existing) {
                    $this->line("  <comment>SKIP</comment> {$filename} — already linked to expense #{$existing->expense_id}");

                    continue;
                }

                $dateStr = $matches[1];
                $amountStr = $matches[2];
                $ext = $matches[3];

                // Parse amount: "194_37" → 194.37, "neg143_38" → -143.38
                $amountStr = str_replace('neg', '-', $amountStr);
                $amountStr = preg_replace('/_(?=\d{1,2}$)/', '.', $amountStr);
                $amount = (float) $amountStr;

                $orphaned[] = [
                    'filePath' => $filePath,
                    'filename' => $filename,
                    'date' => $dateStr,
                    'amount' => $amount,
                    'ext' => $ext,
                ];
            }
        }

        if (empty($orphaned)) {
            $this->info('No orphaned Menards receipt files found.');

            return self::SUCCESS;
        }

        $this->info("Found " . count($orphaned) . " orphaned receipt(s):");
        $this->newLine();

        $created = 0;

        foreach ($orphaned as $receipt) {
            $date = Carbon::parse($receipt['date']);

            $this->line("  {$receipt['filename']} — {$date->format('M j, Y')} — \${$receipt['amount']}");

            if ($dryRun) {
                $this->line("    <comment>WOULD CREATE</comment> expense + receipt record");

                continue;
            }

            // Create expense
            $expense = Expense::create([
                'amount'               => $receipt['amount'],
                'date'                 => $date->format('Y-m-d'),
                'vendor_id'            => $vendorId,
                'belongs_to_vendor_id' => $belongsToVendorId,
                'created_by_user_id'   => 0,
            ]);

            // Rename file to include expense ID
            $newFilename = $expense->id . '-' . $receipt['filename'];
            Storage::disk('files')->move($receipt['filePath'], 'receipts/' . $newFilename);

            // Run OCR
            $ocrData = null;
            $this->line("    <comment>OCR</comment> Analyzing {$newFilename}…");

            try {
                $ocrData = app(ReceiptController::class)
                    ->extractReceipt('receipts/' . $newFilename, $receipt['ext'], abs($receipt['amount']));

                if (isset($ocrData['error'])) {
                    $this->warn("    OCR failed — receipt saved without extracted data");
                    $ocrData = null;
                } else {
                    $itemCount = count($ocrData['fields']['items'] ?? []);
                    $ocrTotal = $ocrData['fields']['total'] ?? '?';
                    $this->line("    <info>OCR</info> {$itemCount} line items, total: \${$ocrTotal}");
                }
            } catch (\Exception $e) {
                $this->warn("    OCR error: {$e->getMessage()}");
                $ocrData = null;
            }

            // Create ExpenseReceipts record
            ExpenseReceipts::create([
                'expense_id'       => $expense->id,
                'receipt_filename' => $newFilename,
                'receipt_html'     => $ocrData['content'] ?? null,
                'receipt_items'    => $ocrData['fields'] ?? null,
            ]);

            $this->line("    <info>CREATED</info> Expense #{$expense->id}");
            $created++;
        }

        $this->newLine();
        $this->info("Done: {$created} expense(s) created from " . count($orphaned) . " orphaned receipt(s).");

        return self::SUCCESS;
    }

    private function findMenardsVendorId(): ?int
    {
        $vendor = Vendor::withoutGlobalScopes()
            ->where('business_name', 'LIKE', '%Menard%')
            ->first();

        return $vendor?->id;
    }
}
