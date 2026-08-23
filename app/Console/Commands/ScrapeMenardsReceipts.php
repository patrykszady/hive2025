<?php

namespace App\Console\Commands;

use App\Http\Controllers\ReceiptController;
use App\Models\Expense;
use App\Models\ExpenseReceipts;
use App\Models\ReceiptAccount;
use App\Models\Vendor;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
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
        {--output-dir= : Custom output directory (default: storage/files/_temp_menards)}';

    protected $description = 'Scrape receipts from menards.com across all cards using Puppeteer + 2captcha and optionally import them';

    public function handle(): int
    {
        $this->line(str_repeat('═', 60));
        $this->info('menards:scrape-receipts — ' . now()->format('Y-m-d H:i:s T'));
        $this->line(str_repeat('═', 60));

        $captchaKey  = config('services.anticaptcha.api_key');
        $outputDir   = $this->option('output-dir') ?: storage_path('files/_temp_menards');
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
            $rawPassword = $options['password'] ?? null;

            try {
                $password = $rawPassword ? Crypt::decryptString($rawPassword) : null;
            } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
                // Value LOOKS like Laravel ciphertext but won't decrypt (wrong
                // APP_KEY — e.g. dev copy of a prod database). Submitting the
                // ciphertext as the password guarantees a confusing
                // "Bad credentials" from Menards, so fail loudly instead.
                if (is_string($rawPassword) && str_starts_with($rawPassword, 'eyJpdiI6')) {
                    $this->error("Receipt account #{$receiptAccount->id} password is encrypted with a different APP_KEY and cannot be decrypted here.");
                    $this->line('If this is a dev copy of the production DB, set the production APP_KEY locally or re-save the credentials.');

                    return self::FAILURE;
                }

                // Fallback for legacy plaintext passwords
                $password = $rawPassword;
            }

            $belongsToVendorId = $receiptAccount->belongs_to_vendor_id;
            $this->info("Using receipt account #{$receiptAccount->id} (belongs_to_vendor_id: {$belongsToVendorId})");
        }

        if (! $skipScrape && (! $email || ! $password)) {
            $this->error('No Menards credentials found in receipt_accounts.options (expected {"email": "...", "password": "..."}).');
            $this->line('Create a receipt_account row with vendor_id=<menards vendor id>, belongs_to_vendor_id=<your company>, options={"email":"...","password":"..."}');

            return self::FAILURE;
        }

        if (! $skipScrape && ! $captchaKey) {
            $this->warn('ANTICAPTCHA_API_KEY not set — will fail if login has a CAPTCHA.');
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
                // When the browser extension has handed us a signed-in session,
                // pass it through and let the scraper skip its own login —
                // Imperva refuses that login even with the captcha solved.
                'cookies'      => \App\Support\MenardsCookieJar::get(),
                // Persistent profile — kept between runs on purpose. A fresh
                // profile every run is what the Imperva wall reacts to.
                'userDataDir'  => storage_path('app/menards-puppeteer'),
            ];

            $configFile = tempnam(sys_get_temp_dir(), 'menards_cfg_');
            file_put_contents($configFile, json_encode($configData));

            $scriptPath = base_path('scripts/menards-scraper.cjs');
            $nodePath   = trim(shell_exec('which node') ?: 'node');

            $this->line("  Output: {$outputDir}");
            $this->line("  Since: {$since->toDateTimeString()}");
            $this->line("  Headless: " . ($headless ? 'yes' : 'no'));
            $jar = \App\Support\MenardsCookieJar::get();
            $this->line('  Session: ' . ($jar === []
                ? 'own login (no browser session paired)'
                : count($jar) . ' borrowed cookie(s), received ' . (\App\Support\MenardsCookieJar::receivedAt() ?? 'unknown')));
            $this->newLine();

            // Headed Chromium on a virtual display, not headless.
            //
            // Imperva fingerprints the browser, not the IP: on 2026-08-22 a
            // headless run from a residential connection got a blank Imperva
            // shell and a redirect loop, while plain curl from the same machine
            // got HTTP 200 in 0.2s. `headless: 'new'` is still detectable. The
            // Yelp automation on gs.construction runs Chromium headed under
            // Xvfb for exactly this reason, and that is the ingredient this
            // scraper was missing.
            //
            // Falls back to running node directly when Xvfb is not installed,
            // so the command still works (headless, and probably walled) rather
            // than failing outright.
            $xvfbRun = trim((string) shell_exec('command -v xvfb-run 2>/dev/null'));
            $command = "{$nodePath} {$scriptPath} {$configFile}";

            if ($xvfbRun !== '' && ! $headless) {
                // -a picks a free display number, so concurrent runs and a
                // logged-in desktop session cannot collide.
                $command = sprintf(
                    '%s -a --server-args=%s %s',
                    $xvfbRun,
                    escapeshellarg('-screen 0 1280x900x24'),
                    $command,
                );
                $this->line('  Display: Xvfb (headed Chromium)');
            } elseif (! $headless) {
                $this->warn('  Xvfb not installed — running headed without a virtual display.');
            }

            $result = Process::timeout(1800) // 30 min max
                ->run($command);

            @unlink($configFile);

            // Show stderr output (scraper logs)
            if ($result->errorOutput()) {
                foreach (explode("\n", trim($result->errorOutput())) as $line) {
                    $this->line("  <comment>{$line}</comment>");
                }
            }

            if (! $result->successful()) {
                // EX_TEMPFAIL (75): a transient outage — anti-captcha had no
                // idle workers, or menards.com/Imperva navigation timed out.
                // Skip quietly; the next scheduled run will try again.
                if ($result->exitCode() === 75) {
                    // Name the actual cause in the log line. "transient outage"
                    // covered three unrelated things, so the log said nothing
                    // about which one happened.
                    $stderr = $result->errorOutput();
                    $reason = match (true) {
                        str_contains($stderr, 'MENARDS_SERVER_ERROR') => 'menards.com returned a 500 — their server, not us',
                        str_contains($stderr, 'PAGE_NOT_HYDRATED') => 'the page loaded but its JavaScript never rendered',
                        str_contains($stderr, 'IMPERVA_BLOCK') => 'Imperva/Incapsula blocked the session',
                        str_contains($stderr, 'LOGIN_REJECTED') => 'credentials were rejected — back on the login page',
                        str_contains($stderr, 'SESSION_NOT_ESTABLISHED') => 'sign-in did not stick (bot wall refused the session)',
                        str_contains($stderr, 'Imperva wall persisted') => 'Imperva wall persisted after repeated challenges',
                        str_contains($stderr, 'no idle workers') => 'captcha solver had no idle workers',
                        default => 'site navigation timed out',
                    };

                    $this->warn("Skipped: {$reason} — will retry on the next scheduled run.");
                    Log::channel('menards')->info('Menards scraper skipped — ' . $reason, [
                        'output_dir' => $outputDir,
                        'stderr_tail' => substr($stderr, -500),
                    ]);

                    return self::SUCCESS;
                }

                $this->error('Scraper process failed (exit code ' . $result->exitCode() . ')');

                // List debug files for diagnosis
                $this->line('');
                $this->line("  <info>Debug files location:</info> {$outputDir}");
                if (is_dir($outputDir)) {
                    $debugFiles = array_filter(scandir($outputDir), fn ($f) => str_starts_with($f, '_debug_'));
                    if (count($debugFiles) > 0) {
                        $this->line('  <info>Debug screenshots/HTML:</info>');
                        foreach ($debugFiles as $f) {
                            $size = filesize($outputDir . '/' . $f);
                            $this->line("    • {$f} ({$size} bytes)");
                        }
                    } else {
                        $this->warn('  No debug files found in output directory');
                    }
                } else {
                    $this->warn("  Output directory does not exist: {$outputDir}");
                }

                Log::error('Menards scraper failed', [
                    'exit_code' => $result->exitCode(),
                    'output_dir' => $outputDir,
                    'stderr_tail' => substr($result->errorOutput(), -2000),
                ]);

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

                // Skip if this expense already has ANY receipt attached.
                //
                // This used to look only for filenames LIKE '%menards-%', which
                // meant an expense already carrying an emailed receipt, a manual
                // upload, or a row whose filename was never set did not count as
                // "already has a receipt" — so a second copy was attached to it.
                // That is how 43 expenses ended up double-linked in one run.
                $existingReceipt = ExpenseReceipts::where('expense_id', $expense->id)->first();

                if ($existingReceipt && ! $force) {
                    $this->line("  <comment>EXISTS</comment> Expense #{$expense->id} already has receipt — skipping (use --force to overwrite)");
                    $linkedExpenseIds[] = $expense->id;
                    $skipped++;

                    continue;
                }

                // --force replaces a receipt this scraper produced. It will not
                // throw away one that arrived another way: an emailed or manually
                // uploaded receipt is the record of something we cannot re-fetch,
                // so it is left alone and the scraped copy is dropped instead.
                if ($existingReceipt && $force) {
                    if (! str_contains((string) $existingReceipt->receipt_filename, 'menards-')) {
                        $this->line("  <comment>KEEP</comment> Expense #{$expense->id} has a non-Menards receipt — leaving it, skipping this one");
                        $linkedExpenseIds[] = $expense->id;
                        $skipped++;

                        continue;
                    }

                    $existingReceipt->delete();
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
