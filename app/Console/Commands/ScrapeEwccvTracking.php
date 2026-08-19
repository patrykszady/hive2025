<?php

namespace App\Console\Commands;

use App\Models\Vendor;
use App\Models\VendorDoc;
use App\Services\NylasService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class ScrapeEwccvTracking extends Command
{
    protected $signature = 'ewccv:scrape-tracking
        {--login-url= : JWT magic link URL (auto-fetched from email if omitted)}
        {--login-email=patryk.szady@live.com : EWCCV account email; a live.com rule forwards its mail to the Nylas-monitored certificates@hive.contractors}
        {--belongs-to-vendor-id= : The belongs_to_vendor_id to scope vendor docs}
        {--vendor-id=* : Specific vendor ID(s) to process (default: all with active WC policies)}
        {--visible : Run browser in visible (non-headless) mode}
        {--dry-run : Show vendors that would be processed without running scraper}
        {--delay= : Base delay between actions in ms (default 3000)}
        {--output-dir= : Custom output directory (default: storage/files/_temp_ewccv)}';

    protected $description = 'Search EWCCV for active workers comp policies and enable email tracking notifications';

    /**
     * Bright Data sticky-session id for this run. Without it the residential
     * exit IP rotates between the email request and the magic-link click, and
     * EWCCV rejects the token.
     */
    private string $proxySession = '';

    public function handle(NylasService $nylasService): int
    {
        $this->proxySession = $this->resolveProxySession();
        $this->line(str_repeat('═', 60));
        $this->info('ewccv:scrape-tracking — ' . now()->format('Y-m-d H:i:s T'));
        $this->line(str_repeat('═', 60));

        $loginUrl   = $this->option('login-url');
        $captchaKey = config('services.anticaptcha.api_key');
        $twoCaptchaKey = config('services.twocaptcha.api_key');
        $outputDir  = $this->option('output-dir') ?: storage_path('files/_temp_ewccv');
        $headless   = ! $this->option('visible');
        $dryRun     = $this->option('dry-run');
        $delay      = (int) ($this->option('delay') ?: 3000);

        // ── Auto-fetch login URL if not provided (skipped during dry-run) ─
        if (! $loginUrl && ! $dryRun) {
            $loginEmail = $this->option('login-email');

            // Try cached token first
            $cachedUrl = $this->getCachedLoginUrl($outputDir);
            if ($cachedUrl) {
                $this->info("Using cached login URL (less than 30 min old)…");
                $loginUrl = $cachedUrl;
            } else {
                $this->info("No --login-url provided. Requesting magic link via email ({$loginEmail})…");

                $loginUrl = $this->fetchLoginUrl($nylasService, $loginEmail, $outputDir, $headless, $delay);

                if (! $loginUrl) {
                    $this->error('Could not obtain EWCCV login URL from email.');

                    return self::FAILURE;
                }

                $this->cacheLoginUrl($outputDir, $loginUrl);
            }

            $this->info("  Got login URL: " . substr($loginUrl, 0, 80) . '…');
        }

        if (! $captchaKey && ! $twoCaptchaKey) {
            $this->warn('Neither ANTICAPTCHA_API_KEY nor TWOCAPTCHA_API_KEY set — reCAPTCHA solving will fail.');
        }

        // ── Load vendors with active WC policies ─────────────────────────
        $belongsToVendorId = $this->option('belongs-to-vendor-id');
        $specificVendorIds = array_filter($this->option('vendor-id'));

        $query = VendorDoc::withoutGlobalScopes()
            ->where('type', 'workers')
            ->where('expiration_date', '>=', Carbon::today());

        if ($belongsToVendorId) {
            $query->where('belongs_to_vendor_id', $belongsToVendorId);
        }

        if (! empty($specificVendorIds)) {
            $query->whereIn('vendor_id', $specificVendorIds);
        }

        $vendorDocs = $query->with('vendor')->get();

        if ($vendorDocs->isEmpty()) {
            $this->warn('No active workers comp policies found.');

            return self::SUCCESS;
        }

        // Build unique vendor list with address data
        $vendors = $vendorDocs->groupBy('vendor_id')->map(function ($docs) {
            $doc = $docs->first();
            $vendor = $doc->vendor;

            if (! $vendor) {
                return null;
            }

            return [
                'id'           => $vendor->id,
                'businessName' => $vendor->business_name,
                'address'      => $vendor->address,
                'city'         => $vendor->city,
                'state'        => $vendor->state,
                'zip'          => $vendor->zip_code,
                'policyNumber' => $doc->number,
                'coverageDate' => Carbon::yesterday()->format('m/d/Y'),
            ];
        })->filter()->values()->toArray();

        // Filter out vendors missing both name and address (need at least one search method)
        $searchable = collect($vendors)->filter(fn ($v) => $v['businessName'] || ($v['state'] && $v['address']));
        $unsearchable = collect($vendors)->reject(fn ($v) => $v['businessName'] || ($v['state'] && $v['address']));

        $this->info("Found {$vendorDocs->count()} active WC policies across " . count($vendors) . ' vendor(s)');
        $this->info("  Searchable: {$searchable->count()}");

        if ($unsearchable->isNotEmpty()) {
            $this->warn("  Missing name & address: {$unsearchable->count()}");
            foreach ($unsearchable as $v) {
                $this->line("    • {$v['businessName']} (ID: {$v['id']}) — missing name and address");
            }
        }

        $this->newLine();

        // Show vendor table
        $this->table(
            ['ID', 'Business Name', 'State', 'Address', 'City', 'Zip', 'Policy #'],
            $searchable->map(fn ($v) => [
                $v['id'],
                mb_strimwidth($v['businessName'], 0, 35, '…'),
                $v['state'],
                mb_strimwidth($v['address'] ?? '', 0, 30, '…'),
                $v['city'] ?? '',
                $v['zip'] ?? '',
                $v['policyNumber'] ?? '—',
            ])->toArray()
        );

        if ($dryRun) {
            $this->info('Dry run — not launching scraper.');

            return self::SUCCESS;
        }

        if ($searchable->isEmpty()) {
            $this->warn('No vendors with sufficient data to search.');

            return self::SUCCESS;
        }

        // ── Run the Puppeteer scraper ─────────────────────────────────────
        $this->info('Launching EWCCV scraper…');

        $configData = [
            'loginUrl'        => $loginUrl,
            'captchaApiKey'   => $captchaKey ?: '',
            'twoCaptchaKey'   => $twoCaptchaKey ?: '',
            'vendors'         => $searchable->values()->toArray(),
            'outputDir'       => $outputDir,
            'screenshotDir'   => public_path('EWCCV'),
            'bypassKey'       => config('services.ewccv.bypass_key') ?: env('EWCCV_BYPASS_KEY', ''),
            // Handed over by the browser extension (EwccvSessionController):
            // a real browser's accessToken, which skips reCAPTCHA entirely.
            'bridgedAccessToken' => $this->bridgedAccessToken(),
            'headless'        => $headless,
            'delayMs'         => $delay,
        ];

        $configFile = tempnam(sys_get_temp_dir(), 'ewccv_cfg_');
        file_put_contents($configFile, json_encode($configData));

        $scriptPath = base_path('scripts/ewccv-scraper.cjs');
        $nodePath = trim(shell_exec('which node') ?: 'node');

        $this->line("  Output: {$outputDir}");
        $this->line("  Vendors: {$searchable->count()}");
        $this->line("  Headless: " . ($headless ? 'yes' : 'no'));
        $this->line("  Delay: {$delay}ms");
        $this->newLine();

        $result = Process::timeout(3600) // 1 hour max
            ->env(['EWCCV_PROXY_SESSION' => $this->proxySession])
            ->run("{$nodePath} {$scriptPath} {$configFile}", function (string $type, string $output): void {
                // Stream stderr (scraper logs) in real-time so the user can see
                // prompts like the manual reCAPTCHA request immediately
                if ($type === 'err') {
                    $this->output->write($output);
                }
            });

        @unlink($configFile);

        // Exit code 2 = login failure (token expired) — retry with fresh token
        if ($result->exitCode() === 2 && ! $this->option('login-url')) {
            $this->warn('Login failed (token may be expired). Clearing cache and fetching fresh token…');
            $this->clearCachedLoginUrl($outputDir);

            $loginEmail = $this->option('login-email');
            $freshUrl = $this->fetchLoginUrl($nylasService, $loginEmail, $outputDir, $headless, $delay);

            if (! $freshUrl) {
                $this->error('Could not obtain fresh EWCCV login URL from email.');

                return self::FAILURE;
            }

            $this->cacheLoginUrl($outputDir, $freshUrl);
            $this->info("  Got fresh login URL: " . substr($freshUrl, 0, 80) . '…');

            // Re-run the scraper with the fresh URL
            $configData['loginUrl'] = $freshUrl;
            $configFile = tempnam(sys_get_temp_dir(), 'ewccv_cfg_');
            file_put_contents($configFile, json_encode($configData));

            $this->info('Retrying scraper with fresh token…');

            $result = Process::timeout(3600)
                ->env(['EWCCV_PROXY_SESSION' => $this->proxySession])
                ->run("{$nodePath} {$scriptPath} {$configFile}", function (string $type, string $output): void {
                    if ($type === 'err') {
                        $this->output->write($output);
                    }
                });

            @unlink($configFile);
        }

        if (! $result->successful()) {
            $this->error('Scraper process failed (exit code ' . $result->exitCode() . ')');
            $this->showDebugFiles($outputDir);

            Log::error('EWCCV scraper failed', [
                'exit_code'  => $result->exitCode(),
                'output_dir' => $outputDir,
                'stderr_tail' => substr($result->errorOutput(), -2000),
            ]);

            return self::FAILURE;
        }

        // ── Parse results ─────────────────────────────────────────────────
        $jsonOutput = $result->output();
        $results = json_decode($jsonOutput, true);

        if (! $results) {
            $this->warn('Could not parse scraper output.');
            $this->showDebugFiles($outputDir);

            return self::FAILURE;
        }

        $this->newLine();
        $this->line(str_repeat('═', 60));
        $this->info('Results Summary');
        $this->line(str_repeat('═', 60));

        if (! empty($results['success'])) {
            $this->info('  Successful (' . count($results['success']) . '):');
            foreach ($results['success'] as $s) {
                $status = $s['alreadyTracking'] ? 'already tracking' : 'tracking enabled';
                $this->line("    ✓ {$s['businessName']} (ID: {$s['vendorId']}) — {$status}");
            }
        }

        if (! empty($results['failed'])) {
            $this->warn('  Failed (' . count($results['failed']) . '):');
            foreach ($results['failed'] as $f) {
                $this->line("    ✗ {$f['businessName']} (ID: {$f['vendorId']}) — {$f['reason']}");
            }
        }

        if (! empty($results['skipped'])) {
            $this->warn('  Skipped (' . count($results['skipped']) . '):');
            foreach ($results['skipped'] as $sk) {
                $this->line("    ⊘ {$sk['businessName']} (ID: {$sk['vendorId']}) — {$sk['reason']}");
            }
        }

        // ── Persist per-doc state so /vendor_docs can show it ─────────────
        // Results are keyed by vendor; stamp every active workers doc for that
        // vendor. saveQuietly: the VendorDocObserver re-queues a lookup when a
        // doc changes, and stamping the lookup's own result must not loop it.
        $stamped = 0;
        $stamp = function (int $vendorId, array $state) use ($vendorDocs, &$stamped): void {
            foreach ($vendorDocs->where('vendor_id', $vendorId) as $doc) {
                $options = $doc->options ?? [];
                $options['ewccv'] = $state + ['checked_at' => now()->toIso8601String()];
                $doc->options = $options;
                $doc->saveQuietly();
                $stamped++;
            }
        };

        foreach ($results['success'] ?? [] as $s) {
            $stamp((int) $s['vendorId'], [
                'status'           => 'tracking',
                'match_type'       => $s['matchType'] ?? null,
                'already_tracking' => (bool) ($s['alreadyTracking'] ?? false),
            ]);
        }
        foreach ($results['failed'] ?? [] as $f) {
            $stamp((int) $f['vendorId'], [
                'status' => 'failed',
                'reason' => $f['reason'] ?? 'unknown',
            ]);
        }
        foreach ($results['skipped'] ?? [] as $sk) {
            $stamp((int) $sk['vendorId'], [
                'status' => 'skipped',
                'reason' => $sk['reason'] ?? 'unknown',
            ]);
        }

        $this->info("Persisted EWCCV state onto {$stamped} vendor doc(s).");

        $successCount = count($results['success'] ?? []);
        $totalCount = $searchable->count();
        $this->newLine();
        $this->info("Done: {$successCount}/{$totalCount} vendors tracked successfully.");

        Log::info('EWCCV scraper completed', [
            'success' => count($results['success'] ?? []),
            'failed'  => count($results['failed'] ?? []),
            'skipped' => count($results['skipped'] ?? []),
        ]);

        return self::SUCCESS;
    }

    /**
     * Request a login email from EWCCV, then poll Nylas for the magic link.
     */
    private function fetchLoginUrl(NylasService $nylasService, string $loginEmail, string $outputDir, bool $headless, int $delay): ?string
    {
        $timestampBefore = now()->subMinute();

        // Step 1: Use Puppeteer to request the login email
        $this->line('  Step 1: Requesting login email via EWCCV…');

        $configData = [
            'mode'       => 'request-email',
            'loginEmail' => $loginEmail,
            'outputDir'  => $outputDir,
            'screenshotDir' => public_path('EWCCV'),
            'headless'   => $headless,
            'delayMs'    => $delay,
            'captchaApiKey' => env('ANTICAPTCHA_API_KEY', ''),
            'twoCaptchaKey' => env('TWOCAPTCHA_API_KEY', ''),
        ];

        $configFile = tempnam(sys_get_temp_dir(), 'ewccv_email_');
        file_put_contents($configFile, json_encode($configData));

        $scriptPath = base_path('scripts/ewccv-scraper.cjs');
        $nodePath = trim(shell_exec('which node') ?: 'node');

        $result = Process::timeout(120)
            ->env(['EWCCV_PROXY_SESSION' => $this->proxySession])
            ->run("{$nodePath} {$scriptPath} {$configFile}");
        @unlink($configFile);

        if ($result->errorOutput()) {
            foreach (explode("\n", trim($result->errorOutput())) as $line) {
                $this->line("    <comment>{$line}</comment>");
            }
        }

        if (! $result->successful()) {
            $this->error('  Failed to request login email (exit code ' . $result->exitCode() . ')');
            $this->showDebugFiles($outputDir);

            return null;
        }

        $emailResult = json_decode($result->output(), true);
        if (empty($emailResult['emailSent'])) {
            $this->error('  EWCCV did not confirm email was sent.');

            return null;
        }

        $this->info('  Login email requested. Polling Nylas for magic link…');

        // Step 2: Poll Nylas for the email with the magic link
        $grantId = config('nylas.certificates_grant_id');
        $maxAttempts = 30;
        $pollInterval = 15; // seconds — the live.com forward adds minutes of latency

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $this->line("    Polling attempt {$attempt}/{$maxAttempts}…");

            sleep($pollInterval);

            try {
                // No folder filter: Outlook rotates folder IDs and the cached
                // one went stale — the subject match below is selective enough.
                $messages = $nylasService->getMessages($grantId, [
                    'limit'   => 20,
                    'received_after' => $timestampBefore->timestamp,
                ]);

                $data = $messages['data'] ?? [];

                if (empty($data)) {
                    continue;
                }

                // Find the most recent matching message (subject may be prefixed with "FW:" / "Fwd:" from forwarding)
                foreach ($data as $message) {
                    $subject = $message['subject'] ?? '';
                    if (stripos($subject, 'Sign in to Workers Compensation Coverage Verification') === false) {
                        continue;
                    }

                    $body = $message['body'] ?? '';

                    // Extract the magic link from the email body
                    if (preg_match('#https://www\.ewccv\.com/cvs/login/\?t=[A-Za-z0-9._-]+#', $body, $matches)) {
                        $this->info("    Found magic link in email (message ID: " . ($message['id'] ?? 'unknown') . ')');

                        $this->fileIntoWorkersCompFolder($nylasService, $grantId, $message['id'] ?? null);

                        return $matches[0];
                    }
                }

                $this->line("    Found " . count($data) . " email(s) but no magic link yet.");
            } catch (\Throwable $e) {
                $this->warn("    Nylas error: {$e->getMessage()}");
            }
        }

        $this->error("  Timed out waiting for EWCCV login email after " . ($maxAttempts * $pollInterval) . ' seconds.');

        return null;
    }

    /**
     * File a processed EWCCV sign-in email into the "Workers Comp" folder on
     * the certificates mailbox. Resolved by NAME (created if missing) — folder
     * IDs on this mailbox have rotated before. Best-effort: a filing failure
     * must never fail the scrape.
     */
    private function fileIntoWorkersCompFolder(NylasService $nylasService, string $grantId, ?string $messageId): void
    {
        if (! $messageId) {
            return;
        }

        try {
            $folders = $nylasService->getFolders($grantId);
            $list = $folders['data']['data'] ?? [];
            $folder = collect($list)->first(fn ($f) => strcasecmp($f['name'] ?? '', 'Workers Comp') === 0);

            if (! $folder) {
                $created = $nylasService->createFolder($grantId, 'Workers Comp');
                $folder = $created['data']['data'] ?? null;
            }

            if (! empty($folder['id'])) {
                $nylasService->moveEmailToFolder($messageId, $folder['id'], $grantId);
                $this->line('    Filed sign-in email into "Workers Comp".');
            }
        } catch (\Throwable $e) {
            $this->warn("    Could not file email into Workers Comp folder: {$e->getMessage()}");
        }
    }

    /**
     * One sticky proxy session per run, cached for 20 minutes so a retry keeps
     * the same exit IP as the email request that preceded it.
     */
    /**
     * The EWCCV search credential most recently pushed by the browser
     * extension, if it is still fresh. Short window on purpose: EWCCV's
     * accessToken is session-scoped, and a stale one just fails the searches
     * that a fresh one would have completed.
     */
    private function bridgedAccessToken(): string
    {
        $session = \Illuminate\Support\Facades\Cache::get(
            \App\Http\Controllers\EwccvSessionController::CACHE_KEY
        );

        if (! is_array($session) || empty($session['access_token'])) {
            return '';
        }

        $age = Carbon::parse($session['received_at'])->diffInMinutes(now());

        if ($age > 45) {
            $this->warn("  Bridged EWCCV session is {$age} min old — ignoring it; open ewccv.com so the extension refreshes it.");

            return '';
        }

        $this->info("  Using EWCCV session from the browser extension ({$age} min old).");

        return (string) $session['access_token'];
    }

    private function resolveProxySession(): string
    {
        $file = ($this->option('output-dir') ?: storage_path('files/_temp_ewccv')).'/.proxy_session.json';

        if (file_exists($file)) {
            $data = json_decode((string) file_get_contents($file), true);
            if (! empty($data['session']) && ! empty($data['at'])
                && Carbon::parse($data['at'])->diffInMinutes(now()) < 20) {
                return (string) $data['session'];
            }
        }

        $session = 'hive'.bin2hex(random_bytes(4));
        @mkdir(dirname($file), 0755, true);
        file_put_contents($file, json_encode(['session' => $session, 'at' => now()->toIso8601String()]));

        return $session;
    }

    private function getCachedLoginUrl(string $outputDir): ?string
    {
        $cacheFile = $outputDir . '/.last_login_url.json';

        if (! file_exists($cacheFile)) {
            return null;
        }

        $data = json_decode(file_get_contents($cacheFile), true);

        if (! $data || empty($data['url']) || empty($data['cached_at'])) {
            return null;
        }

        // Tokens are valid for ~30 minutes
        $cachedAt = Carbon::parse($data['cached_at']);
        if ($cachedAt->diffInMinutes(now()) > 30) {
            $this->line('  Cached login URL is older than 30 minutes — ignoring.');

            return null;
        }

        return $data['url'];
    }

    private function cacheLoginUrl(string $outputDir, string $url): void
    {
        @mkdir($outputDir, 0755, true);
        file_put_contents($outputDir . '/.last_login_url.json', json_encode([
            'url'       => $url,
            'cached_at' => now()->toIso8601String(),
        ]));
    }

    private function clearCachedLoginUrl(string $outputDir): void
    {
        $cacheFile = $outputDir . '/.last_login_url.json';

        if (file_exists($cacheFile)) {
            @unlink($cacheFile);
        }
    }

    private function showDebugFiles(string $outputDir): void
    {
        $this->line('');
        $this->line("  Debug files: {$outputDir}");

        if (is_dir($outputDir)) {
            $debugFiles = array_filter(scandir($outputDir), fn ($f) => str_starts_with($f, '_debug_'));
            if (count($debugFiles) > 0) {
                $this->line('  Screenshots/HTML:');
                foreach ($debugFiles as $f) {
                    $size = filesize($outputDir . '/' . $f);
                    $this->line("    • {$f} ({$size} bytes)");
                }
            }
        }
    }
}
