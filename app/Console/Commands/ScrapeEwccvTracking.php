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
        {--login-email= : EWCCV account email, spelled as the person signs in (default config services.ewccv.login_email); its mail is forwarded to the certificates mailbox}
        {--belongs-to-vendor-id= : The belongs_to_vendor_id to scope vendor docs}
        {--vendor-id=* : Specific vendor ID(s) to process (default: all with active WC policies)}
        {--coverage-date= : Search coverage as of this date (Y-m-d or m/d/Y) instead of yesterday}
        {--verify-only : Record whether EWCCV lists coverage on the date (EwccvCoverageCheck) and create no subscription}
        {--check=* : A coverage check to run alongside, as vendorId:Y-m-d (search as of that date, no subscription); with --check and no --vendor-id, nothing is tracked}
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

    /** The coverage date every search carries: the option, else yesterday. */
    protected function coverageDate(): Carbon
    {
        $raw = (string) $this->option('coverage-date');
        if ($raw === '') {
            return Carbon::yesterday();
        }

        return str_contains($raw, '/') ? Carbon::createFromFormat('m/d/Y', $raw)->startOfDay() : Carbon::parse($raw)->startOfDay();
    }

    /**
     * Vendors for a coverage check, by id — only those with a workers comp
     * policy on file that is active today: EWCCV is only asked about
     * policies Hive currently holds, never about a sub whose certificate has
     * expired or was never collected (those are settled as not found, reason
     * no_active_policy, so nothing retries them). The vendor's address and
     * name are what EWCCV is searched by; the number of the policy in force
     * on the date (else the active one) is what a result is matched on first.
     *
     * @param  list<int>  $vendorIds
     * @return list<array<string, mixed>>
     */
    protected function vendorsForCoverageCheck(array $vendorIds, ?int $belongsToVendorId, Carbon $coverageDate): array
    {
        $docs = VendorDoc::withoutGlobalScopes()
            ->where('type', 'workers')
            ->whereIn('vendor_id', $vendorIds)
            ->when($belongsToVendorId, fn ($query) => $query->where('belongs_to_vendor_id', $belongsToVendorId))
            ->orderByDesc('expiration_date')
            ->get()
            ->groupBy('vendor_id');
        $today = Carbon::today();

        $vendors = \App\Models\Vendor::withoutGlobalScopes()->whereIn('id', $vendorIds)->get();
        $tenantId = (int) ($belongsToVendorId ?: config('services.ewccv.belongs_to_vendor_id', 1));

        return $vendors
            ->filter(function (\App\Models\Vendor $vendor) use ($docs, $today, $coverageDate, $tenantId) {
                $active = collect($docs->get($vendor->id) ?? [])->contains(fn (VendorDoc $doc) => $doc->effective_date && $doc->expiration_date
                    && $doc->effective_date->lte($today) && $doc->expiration_date->gte($today));
                if (! $active) {
                    $this->line("    • {$vendor->business_name} (ID: {$vendor->id}) — no workers comp policy active today, not searched");
                    \App\Models\EwccvCoverageCheck::updateOrCreate(
                        ['vendor_id' => $vendor->id, 'belongs_to_vendor_id' => $tenantId, 'coverage_date' => $coverageDate->copy()->startOfDay()],
                        ['status' => \App\Models\EwccvCoverageCheck::STATUS_NOT_FOUND, 'reason' => 'no_active_policy', 'checked_at' => now()],
                    );
                }

                return $active;
            })
            ->map(function (\App\Models\Vendor $vendor) use ($docs, $coverageDate, $today) {
                $vendorDocs = collect($docs->get($vendor->id) ?? []);
                $inForce = $vendorDocs->first(fn (VendorDoc $doc) => $doc->effective_date && $doc->expiration_date
                    && $doc->effective_date->lte($coverageDate) && $doc->expiration_date->gte($coverageDate));
                $activeNow = $vendorDocs->first(fn (VendorDoc $doc) => $doc->effective_date && $doc->expiration_date
                    && $doc->effective_date->lte($today) && $doc->expiration_date->gte($today));

                return [
                    'key'          => $vendor->id.'@'.$coverageDate->toDateString(),
                    'verifyOnly'   => true,
                    'id'           => $vendor->id,
                    'businessName' => $vendor->business_name,
                    'address'      => $vendor->address,
                    'city'         => $vendor->city,
                    'state'        => $vendor->state,
                    'zip'          => $vendor->zip_code,
                    'policyNumber' => ($inForce ?? $activeNow)?->number,
                    'coverageDate' => $coverageDate->format('m/d/Y'),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Write one EwccvLookupLog row per result entry of this run.
     *
     * @param  array<string, mixed>  $results
     * @param  \Illuminate\Support\Collection<int, VendorDoc>  $vendorDocs  the tracking run's docs, for the doc id
     * @param  \Closure(array): string  $keyOf
     */
    protected function logAttempts(array $results, $vendorDocs, int $tenantId, \Closure $keyOf): void
    {
        $sessionAge = $this->bridgedSessionReceivedAt ? (int) Carbon::parse($this->bridgedSessionReceivedAt)->diffInMinutes(now()) : null;
        $runAt = now();

        foreach (['success', 'failed', 'skipped'] as $list) {
            foreach ($results[$list] ?? [] as $entry) {
                $key = $keyOf($entry);
                $isCheck = str_contains($key, '@');
                $vendorId = (int) $entry['vendorId'];
                $doc = $isCheck ? null : $vendorDocs->firstWhere('vendor_id', $vendorId);
                $outcome = match ($list) {
                    'success' => $isCheck || ! empty($entry['verifyOnly']) ? 'covered' : 'tracking',
                    'skipped' => 'skipped',
                    default => in_array((string) ($entry['reason'] ?? ''), ['no_results', 'no_items_in_response', 'missing_address'], true) ? 'not_found'
                        : ((string) ($entry['reason'] ?? '') === 'no_match' ? 'no_match' : 'failed'),
                };

                \App\Models\EwccvLookupLog::create([
                    'vendor_id' => $vendorId,
                    'vendor_doc_id' => $doc?->id,
                    'belongs_to_vendor_id' => $tenantId,
                    'kind' => $isCheck ? \App\Models\EwccvLookupLog::KIND_COVERAGE_CHECK : \App\Models\EwccvLookupLog::KIND_TRACKING,
                    'coverage_date' => $isCheck ? explode('@', $key, 2)[1] : null,
                    'outcome' => $outcome,
                    'reason' => $list === 'success' ? null : (string) ($entry['reason'] ?? 'unknown'),
                    'our_policy_number' => $doc?->number ?? ($entry['ourPolicyNumber'] ?? null),
                    'matched_name' => $entry['matchedName'] ?? $entry['employerName'] ?? null,
                    'matched_policy' => $entry['matchedPolicy'] ?? $entry['policyNumber'] ?? null,
                    'match_type' => $entry['matchType'] ?? null,
                    'score' => isset($entry['score']) ? (float) $entry['score'] : null,
                    'searched' => array_slice((array) ($entry['searched'] ?? []), 0, 12),
                    'candidates' => array_slice((array) ($entry['candidates'] ?? []), 0, 10),
                    'session_age_minutes' => $sessionAge,
                    'run_at' => $runAt,
                ]);
            }
        }
    }

    /**
     * The --check options, vendor ids by date (Y-m-d).
     *
     * @return array<string, list<int>>
     */
    protected function coverageChecksOption(): array
    {
        $checks = [];
        foreach ((array) $this->option('check') as $spec) {
            if (! preg_match('/^(\d+):(\d{4}-\d{2}-\d{2})$/', trim((string) $spec), $m)) {
                $this->warn("Ignoring --check={$spec}: expected vendorId:Y-m-d");

                continue;
            }
            $checks[$m[2]][] = (int) $m[1];
        }
        ksort($checks);

        return array_map(fn ($ids) => array_values(array_unique($ids)), $checks);
    }

    /**
     * Record the coverage checks of a run on their EwccvCoverageCheck rows: a
     * match is `covered`, a search that found nothing is `not_found`, and a
     * run that failed for its own reasons (spent session, reCAPTCHA, a 500)
     * leaves the check pending with the reason, for the next session.
     *
     * @param  array<string, mixed>  $results
     * @param  list<string>  $allowedKeys  the vendor@date entries this run was asked to check
     * @param  \Closure(array): string  $keyOf
     */
    protected function recordCoverageChecks(array $results, array $allowedKeys, int $belongsToVendorId, \Closure $keyOf): int
    {
        $recorded = 0;
        $record = function (array $entry, array $state) use (&$recorded, $belongsToVendorId, $allowedKeys, $keyOf): void {
            $key = $keyOf($entry);
            if (! in_array($key, $allowedKeys, true) || ! str_contains($key, '@')) {
                return;
            }
            [$vendorId, $date] = explode('@', $key, 2);
            \App\Models\EwccvCoverageCheck::updateOrCreate(
                ['vendor_id' => (int) $vendorId, 'belongs_to_vendor_id' => $belongsToVendorId, 'coverage_date' => Carbon::parse($date)->startOfDay()],
                $state + ['checked_at' => now()],
            );
            $recorded++;
        };

        foreach ($results['success'] ?? [] as $s) {
            $record($s, [
                'status' => \App\Models\EwccvCoverageCheck::STATUS_COVERED,
                'candidates' => array_slice((array) ($s['candidates'] ?? []), 0, 5),
                'policy_number' => $s['policyNumber'] ?? $s['matchedPolicy'] ?? null,
                'employer_name' => $s['employerName'] ?? $s['matchedName'] ?? null,
                'match_type' => $s['matchType'] ?? null,
                'reason' => null,
                'details' => is_array($s['details'] ?? null) ? $s['details'] : null,
                'searched' => array_slice((array) ($s['searched'] ?? []), 0, 8),
            ]);
        }
        foreach (array_merge($results['failed'] ?? [], $results['skipped'] ?? []) as $f) {
            $reason = (string) ($f['reason'] ?? 'unknown');
            $settled = in_array($reason, \App\Models\EwccvCoverageCheck::NOT_FOUND_REASONS, true);
            $record($f, [
                'status' => $settled ? \App\Models\EwccvCoverageCheck::STATUS_NOT_FOUND : \App\Models\EwccvCoverageCheck::STATUS_PENDING,
                'reason' => $reason,
                'searched' => array_slice((array) ($f['searched'] ?? []), 0, 8),
                'candidates' => array_slice((array) ($f['candidates'] ?? []), 0, 5),
            ]);
        }

        return $recorded;
    }

    public function handle(NylasService $nylasService): int
    {
        $this->proxySession = $this->resolveProxySession();
        $this->line(str_repeat('═', 60));
        $this->info('ewccv:scrape-tracking — ' . now()->format('Y-m-d H:i:s T'));
        $this->line(str_repeat('═', 60));

        $loginUrl   = $this->option('login-url');
        // Captcha solvers are off unless EWCCV_USE_CAPTCHA_SOLVERS=true: their
        // tokens are refused by EWCCV (measured), so a key here only spends.
        $useSolvers = (bool) config('services.ewccv.use_captcha_solvers', false);
        $loginEmail = (string) ($this->option('login-email') ?: config('services.ewccv.login_email'));
        $captchaKey = $useSolvers ? config('services.anticaptcha.api_key') : null;
        $twoCaptchaKey = $useSolvers ? config('services.twocaptcha.api_key') : null;
        $outputDir  = $this->option('output-dir') ?: storage_path('files/_temp_ewccv');
        $headless   = ! $this->option('visible');
        $dryRun     = $this->option('dry-run');
        $delay      = (int) ($this->option('delay') ?: 3000);

        // ── Auto-fetch login URL if not provided (skipped during dry-run) ─
        if (! $loginUrl && ! $dryRun) {
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

        if (! $useSolvers) {
            $this->line('  Captcha solvers off (EWCCV refuses their tokens); searches use the bridged browser session or the bypass key.');
        }

        // ── Load vendors with active WC policies ─────────────────────────
        $belongsToVendorId = $this->option('belongs-to-vendor-id');
        $specificVendorIds = array_filter($this->option('vendor-id'));

        $verifyOnly = (bool) $this->option('verify-only');
        $coverageDate = $this->coverageDate();
        $vendorDocs = collect();
        $checks = $this->coverageChecksOption();
        $tenantId = (int) ($belongsToVendorId ?: config('services.ewccv.belongs_to_vendor_id', 1));

        if ($verifyOnly) {
            // An audit's question: was each of these vendors covered on the
            // date? Vendors come by id (any workers doc on file only supplies
            // the policy number to match on), and nothing is subscribed.
            $specificVendorIds = array_map('intval', $specificVendorIds);
            if ($specificVendorIds === []) {
                $this->error('--verify-only needs --vendor-id.');

                return self::FAILURE;
            }
            $vendors = $this->vendorsForCoverageCheck($specificVendorIds, $belongsToVendorId ? (int) $belongsToVendorId : null, $coverageDate);
            $this->info(sprintf('Coverage check as of %s for %d vendor(s)', $coverageDate->format('m/d/Y'), count($vendors)));
        } elseif ($specificVendorIds === [] && $checks !== []) {
            // Coverage checks only: no tracking lookup, so no doc query that
            // would otherwise mean "every active policy".
            $vendors = [];
        } else {
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
            $vendors = $vendorDocs->groupBy('vendor_id')->map(function ($docs) use ($coverageDate) {
                $doc = $docs->first();
                $vendor = $doc->vendor;

                if (! $vendor) {
                    return null;
                }

                return [
                    'key'          => (string) $vendor->id,
                    'id'           => $vendor->id,
                    'businessName' => $vendor->business_name,
                    'address'      => $vendor->address,
                    'city'         => $vendor->city,
                    'state'        => $vendor->state,
                    'zip'          => $vendor->zip_code,
                    'policyNumber' => $doc->number,
                    'coverageDate' => $coverageDate->format('m/d/Y'),
                ];
            })->filter()->values()->toArray();

            $this->info("Found {$vendorDocs->count()} active WC policies across " . count($vendors) . ' vendor(s)');
        }

        // Coverage checks ride along in the same run (one credential, one
        // process): each is its own entry, keyed vendor@date, searched as of
        // its date and never subscribed.
        foreach ($checks as $date => $checkVendorIds) {
            $asOf = Carbon::parse($date)->startOfDay();
            $vendors = array_merge($vendors, $this->vendorsForCoverageCheck($checkVendorIds, $belongsToVendorId ? (int) $belongsToVendorId : null, $asOf));
        }
        if ($checks !== []) {
            $this->info(sprintf('Plus %d coverage check(s) as of %s', array_sum(array_map('count', $checks)), implode(', ', array_keys($checks))));
        }

        // Filter out vendors missing both name and address (need at least one search method)
        $searchable = collect($vendors)->filter(fn ($v) => $v['businessName'] || ($v['state'] && $v['address']));
        $unsearchable = collect($vendors)->reject(fn ($v) => $v['businessName'] || ($v['state'] && $v['address']));

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

        // One scraper at a time: two runs share the Chrome profile and the
        // EWCCV session, and on 2026-10-12 an overlapping pair re-posted a
        // subscription and stamped a freshly tracked policy as failed.
        $lock = \Illuminate\Support\Facades\Cache::lock(\App\Support\Ewccv\ScraperLock::NAME, 3600);
        try {
            $lock->block((int) config('services.ewccv.lock_wait_seconds', 1500));
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException) {
            $this->error('Another EWCCV run is in progress — try again when it finishes.');

            return self::FAILURE;
        }

        try {
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
                'loginEmail' => $loginEmail,
                // Search only, record coverage on the date, no subscription.
                'verifyOnly' => $verifyOnly,
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
                    $status = ! empty($s['verifyOnly'])
                        ? 'covered on '.($s['coverageDate'] ?? '?').' — '.($s['employerName'] ?? '?').', policy '.($s['policyNumber'] ?? '?')
                        : (($s['alreadyTracking'] ?? false) ? 'already tracking' : 'tracking enabled');
                    $this->line("    ✓ {$s['businessName']} (ID: {$s['vendorId']}) — {$status}");
                }
            }

            if (! empty($results['failed'])) {
                $this->warn('  Failed (' . count($results['failed']) . '):');
                foreach ($results['failed'] as $f) {
                    $this->line("    ✗ {$f['businessName']} (ID: {$f['vendorId']}) — {$f['reason']}");
                    // What was searched and the closest employers EWCCV listed,
                    // so a no-result is something a person can act on.
                    foreach ((array) ($f['searched'] ?? []) as $search) {
                        $this->line(sprintf('        searched %s "%s": %s', $search['type'] ?? 'name', $search['term'] ?? '', isset($search['count']) ? $search['count'].' result(s)' : ($search['reason'] ?? '')));
                    }
                    foreach (array_slice((array) ($f['candidates'] ?? []), 0, 5) as $candidate) {
                        $this->line(sprintf('        closest: %s (policy %s, score %s)', $candidate['employerName'] ?? '?', $candidate['policyNumber'] ?? '?', $candidate['score'] ?? '?'));
                    }
                }
            }

            if (! empty($results['skipped'])) {
                $this->warn('  Skipped (' . count($results['skipped']) . '):');
                foreach ($results['skipped'] as $sk) {
                    $this->line("    ⊘ {$sk['businessName']} (ID: {$sk['vendorId']}) — {$sk['reason']}");
                }
            }

            // The browser session's credential ran out mid-run: forget it, so
        // the next hand-off (a fresh one) is what runs next.
        if (! empty($results['sessionExpired'])) {
            \App\Support\Ewccv\BridgedSession::forget($this->bridgedSessionReceivedAt);
            $this->warn('  The bridged EWCCV session is spent — dropped it; open ewccv.com (or press Send session now) for a fresh one.');
        }

            // Every result carries the key of the entry it answers: a plain
            // vendor id is a tracking lookup (stamped on the docs), vendor@date
            // a coverage check (recorded on its EwccvCoverageCheck row). Older
            // scraper output without keys is read by vendor id.
            $keyOf = fn (array $entry): string => (string) ($entry['key']
                ?? ($verifyOnly ? $entry['vendorId'].'@'.$coverageDate->toDateString() : $entry['vendorId']));
            $isCheck = fn (array $entry): bool => str_contains($keyOf($entry), '@');
            $allowedChecks = [];
            foreach ($searchable as $entry) {
                if (! empty($entry['verifyOnly'])) {
                    $allowedChecks[] = $entry['key'];
                }
            }

            // Every attempt goes to the review log (ewccv_lookup_logs) with what
            // was searched and what EWCCV listed — the doc keeps only the last.
            $this->logAttempts($results, $vendorDocs, $tenantId, $keyOf);

            $recorded = $this->recordCoverageChecks($results, $allowedChecks, $tenantId, $keyOf);
            if ($recorded > 0 || $verifyOnly || $checks !== []) {
                $this->info("Recorded EWCCV coverage for {$recorded} coverage check(s).");
                Log::info('EWCCV coverage checks completed', ['recorded' => $recorded, 'dates' => $verifyOnly ? [$coverageDate->toDateString()] : array_keys($checks)]);
            }
            if ($verifyOnly) {
                return self::SUCCESS;
            }
            $results = [
                'success' => array_values(array_filter($results['success'] ?? [], fn ($e) => ! $isCheck($e))),
                'failed' => array_values(array_filter($results['failed'] ?? [], fn ($e) => ! $isCheck($e))),
                'skipped' => array_values(array_filter($results['skipped'] ?? [], fn ($e) => ! $isCheck($e))),
            ] + $results;

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
                    // What EWCCV said to the "you are now tracking" email step.
                    'confirmation'     => $s['confirmation'] ?? null,
                    'subscription_id'  => $s['subscriptionId'] ?? null,
                    'matched_name'     => $s['matchedName'] ?? null,
                    'matched_policy'   => $s['matchedPolicy'] ?? null,
                ]);
            }
            foreach ($results['failed'] ?? [] as $f) {
                $stamp((int) $f['vendorId'], [
                    'status' => 'failed',
                    'reason' => $f['reason'] ?? 'unknown',
                    // The searches tried and the closest employers, for the tip.
                    'searched' => array_slice((array) ($f['searched'] ?? []), 0, 8),
                    'candidates' => array_slice((array) ($f['candidates'] ?? []), 0, 5),
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
        } finally {
            $lock->release();
        }
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
    /** received_at of the session this run took, so only that one is dropped when spent. */
    private ?string $bridgedSessionReceivedAt = null;

    private function bridgedAccessToken(): string
    {
        $session = \App\Support\Ewccv\BridgedSession::fresh();
        $this->bridgedSessionReceivedAt = $session['received_at'] ?? null;

        if (! $session) {
            $this->warn('  No fresh EWCCV browser session (under '.\App\Support\Ewccv\BridgedSession::MAX_AGE_MINUTES.' min) — open ewccv.com so the extension hands one over.');

            return '';
        }

        $this->info("  Using EWCCV session from the browser extension ({$session['age_minutes']} min old).");

        return $session['access_token'];
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
