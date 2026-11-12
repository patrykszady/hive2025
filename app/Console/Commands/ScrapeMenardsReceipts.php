<?php

namespace App\Console\Commands;

use App\Http\Controllers\ReceiptController;
use App\Models\Expense;
use App\Models\ExpenseReceipts;
use App\Models\ReceiptAccount;
use App\Models\Vendor;
use App\Support\ReceiptTenders;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

class ScrapeMenardsReceipts extends Command
{
    protected $signature = 'menards:scrape-receipts
        {--belongs-to-vendor-id= : The belongs_to_vendor_id that owns this Menards account (auto-detected if only one exists)}
        {--since= : Only import receipts on or after this date (e.g. 2026-07-01). Defaults to last_queried_at or 90 days ago}
        {--dry-run : Parse the manifest and report, do not write to the database}
        {--match-expenses : Match imported receipts to existing expenses by date + amount}
        {--force : Overwrite existing Menards receipts (re-run OCR)}
        {--vendor-id= : Menards vendor ID (auto-detected if omitted)}
        {--skip-scrape : No-op. Importing is the only mode; the flag is kept so existing callers keep working}
        {--output-dir= : Custom output directory (default: storage/files/_temp_menards)}
        {--lock-wait=1500 : Seconds to wait for another import to finish}';

    protected $description = 'Import Menards receipts that the browser extension collected, matching them to expenses';

    /** Held for the whole of one import; see handle(). */
    public const IMPORT_LOCK = 'menards-receipt-import';

    /**
     * One import at a time. On 2026-10-02 two batches of the same receipts
     * (a sync asked for by hand and the scheduled one) were imported side by
     * side: the second found the expense the first had just created, before
     * its receipt was attached, and each attached a copy. The second batch
     * now waits for the first, then finds the receipt and skips it.
     */
    public function handle(): int
    {
        if ($this->option('dry-run')) {
            return $this->import();
        }

        $lock = Cache::lock(self::IMPORT_LOCK, 1800);

        try {
            $lock->block((int) $this->option('lock-wait'));
        } catch (LockTimeoutException) {
            $this->error('Another Menards import is still running — try again when it finishes.');

            return self::FAILURE;
        }

        try {
            return $this->import();
        } finally {
            $lock->release();
        }
    }

    protected function import(): int
    {
        $this->line(str_repeat('═', 60));
        $this->info('menards:scrape-receipts — ' . now()->format('Y-m-d H:i:s T'));
        $this->line(str_repeat('═', 60));

        $outputDir   = $this->option('output-dir') ?: storage_path('files/_temp_menards');
        $dryRun      = $this->option('dry-run');
        $matchExp    = $this->option('match-expenses');
        $force       = $this->option('force');

        // ── Load Menards credentials from receipt_accounts ────────────────
        $menardsVendorId = $this->option('vendor-id') ?: $this->findMenardsVendorId();
        $belongsToVendorId = $this->option('belongs-to-vendor-id');

        $receiptAccount = $this->findReceiptAccount($menardsVendorId, $belongsToVendorId);

        if ($receiptAccount) {
            $belongsToVendorId = $receiptAccount->belongs_to_vendor_id;
            $this->info("Using receipt account #{$receiptAccount->id} (belongs_to_vendor_id: {$belongsToVendorId})");
        }

        // ── Determine date cutoff ─────────────────────────────────────────
        $sinceOption = $this->option('since');
        if ($sinceOption) {
            $since = Carbon::parse($sinceOption)->startOfDay();
        } elseif ($receiptAccount && isset($receiptAccount->options['last_queried_at'])) {
            $since = Carbon::parse($receiptAccount->options['last_queried_at']);
            $this->info("Using last_queried_at from receipt account: {$since->toDateTimeString()}");
        } else {
            $since = Carbon::now()->subDays(90)->startOfDay();
            $this->info("No --since or last_queried_at — defaulting to last 90 days ({$since->toDateString()})");
        }


        // The Puppeteer scraper that used to run here is gone.
        //
        // Imperva answers a non-browser client with a 930-byte "Incapsula
        // incident ID" block page served as HTTP 200, and a CDP-driven Chromium
        // with an unrendered shell. It had not fetched a receipt since
        // mid-August 2026, and because the block arrives as a 200 the failures
        // read as successes for two weeks.
        //
        // Fetching now happens inside the signed-in browser on the server: the
        // receipt extension calls Menards' own JSON endpoints and POSTs the
        // results to /api/menards/receipts, which queues ImportMenardsReceiptBatch,
        // which calls this command with --skip-scrape. Everything below — the
        // manifest read, OCR, expense matching and de-duplication — is unchanged
        // and is the only path still in use.


        // ── Step 2: Read manifest ─────────────────────────────────────────
        $manifestPath = $outputDir . '/manifest.json';
        if (! file_exists($manifestPath)) {
            $this->error("Manifest not found: {$manifestPath}");

            return self::FAILURE;
        }

        $manifest = json_decode(file_get_contents($manifestPath), true);
        if (! $manifest || isset($manifest['error'])) {
            $this->error('Scraper reported error: ' . ($manifest['error'] ?? 'unknown'));

            return self::FAILURE;
        }

        $receipts = $manifest['receipts'] ?? [];
        $this->info("Scraped {$manifest['totalReceipts']} receipts at {$manifest['scrapedAt']}");

        if ($dryRun) {
            $this->table(
                ['#', 'Card', 'Date', 'Store', 'Amount', 'File'],
                collect($receipts)->map(fn ($r, $i) => [
                    $i + 1,
                    $r['card'] ?? '—',
                    $r['date'],
                    $r['store'],
                    $r['amount'],
                    $r['file'] ?? '—',
                ])->toArray()
            );

            return self::SUCCESS;
        }

        // ── Step 3: Import receipts ───────────────────────────────────────
        if (! $matchExp) {
            $this->info('Scraping complete. Use --match-expenses to import into database.');
            $this->line("Receipt files saved to: {$outputDir}");

            return self::SUCCESS;
        }

        $vendorId = $menardsVendorId;
        if (! $vendorId) {
            $this->error('Could not determine Menards vendor_id. Pass --vendor-id=N explicitly.');

            return self::FAILURE;
        }

        $this->info("Menards vendor_id: {$vendorId}");

        $imported = 0;
        $matched  = 0;
        $skipped  = 0;
        $linkedExpenseIds = [];

        foreach ($receipts as $receipt) {
            $amount = $this->parseAmount($receipt['amount']);
            $date   = $this->parseDate($receipt['date']);

            if (! $date) {
                $this->warn("  Skipping receipt with unparseable date: {$receipt['date']}");
                $skipped++;

                continue;
            }

            // Read receipt file (PDF or other)
            $receiptFile = $receipt['file'] ?? null;
            $receiptContent = null;
            $sourceFilePath = null;

            if ($receiptFile) {
                $sourceFilePath = $outputDir . '/' . $receiptFile;
                if (file_exists($sourceFilePath)) {
                    $receiptContent = file_get_contents($sourceFilePath);
                }
            }

            // Try to match to an existing expense: same day and Menards' total,
            // or the expense this receipt was imported to before — its amount
            // may have been raised to the full total since (rebate checks, see
            // below), and the receipt file name still carries Menards' total.
            $receiptStem = sprintf('-menards-%s-%s.', $date->format('Y-m-d'), str_replace(['.', '-'], ['_', 'neg'], (string) $amount));
            $expense = Expense::withoutGlobalScopes()
                ->where('vendor_id', $vendorId)
                ->whereDate('date', $date->format('Y-m-d'))
                ->where(fn ($query) => $query
                    ->where('amount', $amount)
                    ->orWhereHas('receipts', fn ($receipts) => $receipts->where('receipt_filename', 'like', '%'.$receiptStem.'%')))
                ->first();

            if ($expense) {
                if (in_array($expense->id, $linkedExpenseIds)) {
                    $this->line("  <comment>DUPE</comment> Expense #{$expense->id} already linked — skipping {$receipt['card']}");
                    $skipped++;

                    continue;
                }

                // One copy from menards.com per expense, never two: on one run
                // 43 expenses ended up with a second copy of the same download.
                //
                // A receipt that arrived another way — the office's scan of the
                // paper, an upload — is NOT a reason to skip. Until 2026-11-12
                // it was: the scan of a morning's purchase usually reaches Hive
                // before Menards' own copy does, so that copy was fetched and
                // then thrown away (expenses 29066 and 29067, 2026-11-11), and
                // the expense kept only the scan. Now Menards' copy goes on
                // beside it, the way a scan and an emailed receipt of one
                // purchase already sit together (attachBesideOtherCopies()).
                $menardsCopy = $this->menardsCopyOn($expense->id);

                if ($menardsCopy && ! $force) {
                    $this->line("  <comment>EXISTS</comment> Expense #{$expense->id} already has Menards' receipt — skipping (use --force to overwrite)");
                    $linkedExpenseIds[] = $expense->id;
                    $skipped++;

                    continue;
                }

                // --force replaces a receipt this importer produced. It never
                // touches one that arrived another way: a scan or an upload is
                // the record of something we cannot fetch again.
                if ($menardsCopy && $force) {
                    $menardsCopy->delete();
                    $this->line("  <comment>REPLACE</comment> Deleting old receipt for expense #{$expense->id}");
                }

                $this->line("  <info>MATCH</info> Expense #{$expense->id} ← {$receipt['date']} — {$receipt['amount']}");
                $matched++;
            } else {
                // Create a new expense automatically (same as email receipt flow)
                $expense = Expense::create([
                    'amount'               => $amount,
                    'date'                 => $date->format('Y-m-d'),
                    'vendor_id'            => $vendorId,
                    'belongs_to_vendor_id' => $belongsToVendorId,
                    'created_by_user_id'   => 0,
                ]);

                $this->line("  <info>CREATED</info> Expense #{$expense->id} ← {$receipt['date']} — {$receipt['amount']}");
                $matched++;
            }

            // Save receipt file to permanent storage
            $filename = null;
            $ocrData = null;
            if ($receiptContent && $sourceFilePath) {
                $safeDateStr = $date->format('Y-m-d');
                $safeAmount  = str_replace(['.', '-'], ['_', 'neg'], (string) $amount);
                $ext = pathinfo($receiptFile, PATHINFO_EXTENSION) ?: 'pdf';
                $baseFilename = "menards-{$safeDateStr}-{$safeAmount}.{$ext}";
                $filename = $expense ? $expense->id . '-' . $baseFilename : $baseFilename;

                Storage::disk('files')->put('receipts/' . $filename, $receiptContent);

                // Run Azure Content Understanding OCR on the receipt
                $this->line("    <comment>OCR</comment> Analyzing {$filename}…");

                try {
                    $ocrData = app(ReceiptController::class)
                        ->extractReceipt('receipts/' . $filename, $ext, abs($amount));

                    if (isset($ocrData['error'])) {
                        $this->warn("    OCR failed for {$filename} — receipt saved without extracted data");
                        $ocrData = null;
                    } else {
                        $itemCount = count($ocrData['fields']['items'] ?? []);
                        $ocrTotal = $ocrData['fields']['total'] ?? '?';
                        $this->line("    <info>OCR</info> {$itemCount} line items, total: \${$ocrTotal}");
                    }
                } catch (\Exception $e) {
                    $this->warn("    OCR error for {$filename}: {$e->getMessage()}");
                    $ocrData = null;
                }
            }

            // Last look before attaching: never a second copy from menards.com on one expense.
            if ($expense && ! $force && $this->menardsCopyOn($expense->id)) {
                $this->line("  <comment>EXISTS</comment> Expense #{$expense->id} got Menards' receipt meanwhile — skipping");
                $linkedExpenseIds[] = $expense->id;
                $skipped++;

                continue;
            }

            if ($expense) {
                // Rebate checks: the receipt's subtotal / tax / total describe the
                // purchase, not what Menards printed after the checks.
                if (is_array($ocrData['fields'] ?? null)) {
                    $ocrData['fields'] = ReceiptTenders::withMenardsRebateTotals($ocrData['fields'], (string) ($ocrData['fields']['raw_content'] ?? $ocrData['content'] ?? ''));
                }

                $this->attachBesideOtherCopies($expense, $filename, $ocrData);

                $linkedExpenseIds[] = $expense->id;

                // Rebate checks spent at the register print before the total,
                // so Menards' transaction total (what $amount is) is what was
                // left after them. The purchase cost TOTAL SALE + the checks.
                $rebateTotal = ReceiptTenders::menardsRebateTotal((string) ($ocrData['fields']['raw_content'] ?? $ocrData['content'] ?? ''));

                if ($rebateTotal && abs((float) $expense->amount - $rebateTotal['sale']) <= 0.02 && abs($rebateTotal['total'] - (float) $expense->amount) > 0.02) {
                    $expense->update(['amount' => $rebateTotal['total']]);
                    $this->line(sprintf('    <info>REBATE</info> %s in rebate checks: amount %s → %s', number_format($rebateTotal['rebates'], 2), number_format($rebateTotal['sale'], 2), number_format($rebateTotal['total'], 2)));
                }
            }

            $imported++;
        }

        $this->newLine();
        $this->info("Done: {$imported} imported, {$matched} matched to expenses, {$skipped} skipped");

        return self::SUCCESS;
    }

    /** The copy this importer attached to the expense before, if any — its files are named "…menards-…". */
    private function menardsCopyOn(int $expenseId): ?ExpenseReceipts
    {
        return ExpenseReceipts::where('expense_id', $expenseId)
            ->where('receipt_filename', 'like', '%menards-%')
            ->first();
    }

    /**
     * Attach Menards' own copy of a receipt. When the expense already holds
     * another copy of the same purchase (the office's scan, an upload), both
     * stay, by the rule the scan-plus-emailed-receipt case already follows
     * (ExpenseReceipts::itemsSupersede): the copy whose line items add up
     * better carries them, and the other keeps its file, text, totals and
     * handwritten notes with its items set aside, so nothing is counted twice.
     *
     * @param  array{content?: ?string, fields?: ?array<string, mixed>}|null  $ocrData
     */
    private function attachBesideOtherCopies(Expense $expense, ?string $filename, ?array $ocrData): ExpenseReceipts
    {
        $fields = is_array($ocrData['fields'] ?? null) ? $ocrData['fields'] : null;

        $otherCopy = ExpenseReceipts::where('expense_id', $expense->id)->orderBy('id')->get()
            ->first(fn (ExpenseReceipts $receipt) => ! $receipt->isSupplement());

        $menardsItemsWin = $otherCopy !== null
            && $fields !== null
            && ExpenseReceipts::itemsSupersede($fields, $otherCopy->receipt_items ?? []);

        if ($otherCopy !== null && $fields !== null && ! $menardsItemsWin) {
            $fields = ExpenseReceipts::toSupplementReceiptItems($fields, $otherCopy->id);
        }

        $receipt = ExpenseReceipts::create([
            'expense_id'       => $expense->id,
            'receipt_filename' => $filename,
            'receipt_html'     => $ocrData['content'] ?? null,
            'receipt_items'    => $fields,
        ]);

        if ($menardsItemsWin) {
            $otherCopy->demoteToSupplementOf($receipt);
        }

        if ($otherCopy !== null) {
            $this->line("    <info>BESIDE</info> Expense #{$expense->id} keeps the receipt it had; Menards' copy added next to it"
                . ' (line items from ' . ($menardsItemsWin ? "Menards' copy" : 'the one it had') . ')');
        }

        return $receipt;
    }

    private function findMenardsVendorId(): ?int
    {
        $vendor = Vendor::withoutGlobalScopes()
            ->where('business_name', 'LIKE', '%Menard%')
            ->first();

        return $vendor?->id;
    }

    private function findReceiptAccount(?int $menardsVendorId, ?string $belongsToVendorId): ?ReceiptAccount
    {
        $query = ReceiptAccount::withoutGlobalScopes()
            ->whereNotNull('options->email');

        if ($menardsVendorId) {
            $query->where('vendor_id', $menardsVendorId);
        }

        if ($belongsToVendorId) {
            $query->where('belongs_to_vendor_id', $belongsToVendorId);
        }

        $accounts = $query->get();

        if ($accounts->isEmpty()) {
            return null;
        }

        if ($accounts->count() > 1 && ! $belongsToVendorId) {
            $this->warn('Multiple Menards receipt accounts found. Use --belongs-to-vendor-id to pick one:');
            foreach ($accounts as $a) {
                $this->line("  ID #{$a->id}  belongs_to_vendor_id={$a->belongs_to_vendor_id}  email={$a->options['email']}");
            }

            return null;
        }

        return $accounts->first();
    }

    private function parseAmount(string $raw): float
    {
        // "$194.37" or "-$143.38" → 194.37 or -143.38
        $cleaned = preg_replace('/[^0-9.\-]/', '', $raw);

        return (float) $cleaned;
    }

    private function parseDate(string $raw): ?Carbon
    {
        // "March 20, 2026 @ 2:07 PM" → Carbon
        $cleaned = str_replace('@', '', $raw);
        $cleaned = preg_replace('/\s+/', ' ', trim($cleaned));

        try {
            return Carbon::parse($cleaned);
        } catch (\Exception $e) {
            return null;
        }
    }
}
