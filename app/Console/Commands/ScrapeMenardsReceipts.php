<?php

namespace App\Console\Commands;

use App\Http\Controllers\ReceiptController;
use App\Models\Expense;
use App\Models\ExpenseReceipts;
use App\Models\ReceiptAccount;
use App\Models\Vendor;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

class ScrapeMenardsReceipts extends Command
{
    protected $signature = 'menards:scrape-receipts
        {--belongs-to-vendor-id= : The belongs_to_vendor_id that owns this Menards account (auto-detected if only one exists)}
        {--since= : Only scrape receipts on or after this date (e.g. 2025-03-01). Defaults to last_queried_at or 10 days ago}
        {--visible : Run browser in visible (non-headless) mode}
        {--dry-run : Scrape only, do not import into database}
        {--match-expenses : Try to match scraped receipts to existing expenses by date + amount}
        {--force : Overwrite existing Menards receipts (re-run OCR)}
        {--vendor-id= : Menards vendor ID (auto-detected if omitted)}
        {--skip-scrape : Skip scraping, just import from existing output directory}
        {--output-dir= : Custom output directory (default: storage/app/menards-receipts)}';

    protected $description = 'Scrape receipts from menards.com across all cards using Puppeteer + 2captcha and optionally import them';

    public function handle(): int
    {
        $captchaKey  = config('services.two_captcha.api_key');
        $outputDir   = $this->option('output-dir') ?: storage_path('app/menards-receipts');
        $headless    = ! $this->option('visible');
        $dryRun      = $this->option('dry-run');
        $matchExp    = $this->option('match-expenses');
        $force       = $this->option('force');
        $skipScrape  = $this->option('skip-scrape');

        // ── Load Menards credentials from receipt_accounts ────────────────
        $menardsVendorId = $this->option('vendor-id') ?: $this->findMenardsVendorId();
        $belongsToVendorId = $this->option('belongs-to-vendor-id');

        $receiptAccount = $this->findReceiptAccount($menardsVendorId, $belongsToVendorId);

        $email    = null;
        $password = null;

        if ($receiptAccount) {
            $options  = $receiptAccount->options ?? [];
            $email    = $options['email'] ?? null;
            $password = $options['password'] ?? null;
            $belongsToVendorId = $receiptAccount->belongs_to_vendor_id;
            $this->info("Using receipt account #{$receiptAccount->id} (belongs_to_vendor_id: {$belongsToVendorId})");
        }

        if (! $skipScrape && (! $email || ! $password)) {
            $this->error('No Menards credentials found in receipt_accounts.options (expected {"email": "...", "password": "..."}).');
            $this->line('Create a receipt_account row with vendor_id=<menards vendor id>, belongs_to_vendor_id=<your company>, options={"email":"...","password":"..."}');

            return self::FAILURE;
        }

        if (! $skipScrape && ! $captchaKey) {
            $this->warn('TWOCAPTCHA_API_KEY not set — will fail if login has a CAPTCHA.');
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

        // ── Step 1: Run the Puppeteer scraper ─────────────────────────────
        if (! $skipScrape) {
            $this->info('Launching Menards receipt scraper…');

            $configData = [
                'email'        => $email,
                'password'     => $password,
                'captchaApiKey' => $captchaKey ?: '',
                'outputDir'    => $outputDir,
                'headless'     => $headless,
                'since'        => $since->toIso8601String(),
                'delayMs'      => 2000,
            ];

            $configFile = tempnam(sys_get_temp_dir(), 'menards_cfg_');
            file_put_contents($configFile, json_encode($configData));

            $scriptPath = base_path('scripts/menards-scraper.cjs');
            $nodePath   = trim(shell_exec('which node') ?: 'node');

            $this->line("  Output: {$outputDir}");
            $this->line("  Since: {$since->toDateTimeString()}");
            $this->line("  Headless: " . ($headless ? 'yes' : 'no'));
            $this->newLine();

            $result = Process::timeout(1800) // 30 min max
                ->run("{$nodePath} {$scriptPath} {$configFile}");

            @unlink($configFile);

            // Show stderr output (scraper logs)
            if ($result->errorOutput()) {
                foreach (explode("\n", trim($result->errorOutput())) as $line) {
                    $this->line("  <comment>{$line}</comment>");
                }
            }

            if (! $result->successful()) {
                $this->error('Scraper process failed (exit code ' . $result->exitCode() . ')');

                return self::FAILURE;
            }

            // ── Update last_queried_at on the receipt account ─────────────
            if ($receiptAccount) {
                $opts = $receiptAccount->options ?? [];
                $opts['last_queried_at'] = Carbon::now()->toIso8601String();
                $receiptAccount->options = $opts;
                $receiptAccount->save();
                $this->info("Updated last_queried_at on receipt account #{$receiptAccount->id}");
            }
        }

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

            // Try to match to an existing expense
            $expense = Expense::withoutGlobalScopes()
                ->where('vendor_id', $vendorId)
                ->whereDate('date', $date->format('Y-m-d'))
                ->where('amount', $amount)
                ->first();

            if ($expense) {
                if (in_array($expense->id, $linkedExpenseIds)) {
                    $this->line("  <comment>DUPE</comment> Expense #{$expense->id} already linked — skipping {$receipt['card']}");
                    $skipped++;

                    continue;
                }

                // Skip if this expense already has a Menards receipt attached
                $existingReceipt = ExpenseReceipts::where('expense_id', $expense->id)
                    ->where('receipt_filename', 'LIKE', 'menards-%')
                    ->first();

                if ($existingReceipt && ! $force) {
                    $this->line("  <comment>EXISTS</comment> Expense #{$expense->id} already has receipt — skipping (use --force to overwrite)");
                    $linkedExpenseIds[] = $expense->id;
                    $skipped++;

                    continue;
                }

                if ($existingReceipt && $force) {
                    $existingReceipt->delete();
                    $this->line("  <comment>REPLACE</comment> Deleting old receipt for expense #{$expense->id}");
                }

                $this->line("  <info>MATCH</info> Expense #{$expense->id} ← {$receipt['date']} — {$receipt['amount']}");
                $matched++;
            } else {
                $this->line("  <comment>NO MATCH</comment> {$receipt['date']} — {$receipt['amount']}  (receipt saved, no expense link)");
                $expense = null;
            }

            // Save receipt file to permanent storage
            $filename = null;
            $ocrData = null;
            if ($receiptContent && $sourceFilePath) {
                $safeDateStr = $date->format('Y-m-d');
                $safeAmount  = str_replace(['.', '-'], ['_', 'neg'], (string) $amount);
                $ext = pathinfo($receiptFile, PATHINFO_EXTENSION) ?: 'pdf';
                $filename = "menards-{$safeDateStr}-{$safeAmount}.{$ext}";

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

            if ($expense) {
                ExpenseReceipts::create([
                    'expense_id'       => $expense->id,
                    'receipt_filename' => $filename,
                    'receipt_html'     => $ocrData['content'] ?? null,
                    'receipt_items'    => $ocrData['fields'] ?? null,
                ]);

                $linkedExpenseIds[] = $expense->id;
            }

            $imported++;
        }

        $this->newLine();
        $this->info("Done: {$imported} imported, {$matched} matched to expenses, {$skipped} skipped");

        return self::SUCCESS;
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
