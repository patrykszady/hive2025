<?php

namespace App\Console\Commands;

use App\Models\VendorDoc;
use App\Services\NylasService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Sync what EWCCV is tracking for us onto our workers-comp documents.
 *
 * This is the part of the EWCCV integration that runs unattended. EWCCV gates
 * SEARCH behind reCAPTCHA v3 Enterprise — a score, not a puzzle, which no
 * captcha service can supply and no automated browser passes. But its
 * subscription service authenticates with the ordinary login JWT that the
 * magic-link sign-in produces. Measured on 2026-08-16:
 *
 *   GET /cvs/endpoint/subscription/subscription/all → 200 with the login JWT
 *   GET /cvs/endpoint/insureds/coverage/...         → 401 with the login JWT
 *
 * So: adding a NEW policy to tracking needs a search (a human moment, or a
 * bypass key from NCCI), but confirming what is already tracked runs headless
 * on a schedule, forever. A tracked policy is one EWCCV has confirmed exists
 * and will email us about if it is cancelled or not renewed — which is the
 * actual compliance question.
 *
 * Read-only against EWCCV.
 */
class SyncEwccvSubscriptions extends Command
{
    protected $signature = 'ewccv:sync-subscriptions
        {--login-email=patryk.szady@live.com : Mailbox that receives the EWCCV sign-in link}
        {--belongs-to-vendor-id= : Limit the docs we stamp to one owning vendor}
        {--output-dir= : Working dir (default storage/files/_temp_ewccv)}
        {--dry-run : Show what EWCCV tracks without writing to vendor docs}';

    protected $description = 'Read EWCCV tracking subscriptions (no captcha) and stamp them onto workers comp docs';

    public function handle(NylasService $nylasService): int
    {
        $outputDir = $this->option('output-dir') ?: storage_path('files/_temp_ewccv');
        @mkdir($outputDir, 0755, true);

        $this->line(str_repeat('═', 62));
        $this->info('ewccv:sync-subscriptions — '.now()->format('Y-m-d H:i:s T'));
        $this->line(str_repeat('═', 62));

        // Reuse a recent sign-in link if one is still warm, else ask for a new
        // one. NEVER through the proxy: EWCCV silently drops sign-in emails for
        // requests from those IPs (measured — direct delivers in ~20s).
        $loginUrl = $this->cachedLoginUrl($outputDir) ?: $this->fetchLoginUrl($nylasService, $outputDir);

        if (! $loginUrl) {
            $this->error('Could not obtain an EWCCV sign-in link.');

            return self::FAILURE;
        }

        $configFile = tempnam(sys_get_temp_dir(), 'ewccv_subs_');
        file_put_contents($configFile, json_encode([
            'loginUrl' => $loginUrl,
            'outputDir' => $outputDir,
            'headless' => true,
        ]));

        $result = Process::timeout(300)
            ->env($this->directNetworkEnv())
            ->run(sprintf(
                '%s %s %s',
                trim((string) shell_exec('which node')) ?: 'node',
                base_path('scripts/ewccv-subscriptions.cjs'),
                $configFile
            ), function (string $type, string $output): void {
                if ($type === 'err') {
                    $this->output->write($output);
                }
            });

        @unlink($configFile);

        $payload = json_decode(trim($result->output()), true);

        if (! is_array($payload) || empty($payload['ok'])) {
            $this->error('  Could not read subscriptions'.(! empty($payload['error']) ? ": {$payload['error']}" : '.'));
            // A dead link is the usual cause — drop it so the next run re-requests.
            $this->clearCachedLoginUrl($outputDir);

            return self::FAILURE;
        }

        $subscriptions = collect($payload['subscriptions'] ?? []);
        $this->info('  EWCCV is tracking '.$subscriptions->count().' policy(ies) for this account.');
        $this->newLine();

        if ($subscriptions->isEmpty()) {
            $this->warn('  Nothing tracked yet — add a policy to tracking on ewccv.com and re-run.');

            return self::SUCCESS;
        }

        $this->table(
            ['Employer', 'Policy #', 'State', 'Tracking since'],
            $subscriptions->map(fn ($s) => [
                mb_strimwidth((string) ($s['employerInformation']['employer'] ?? '—'), 0, 34, '…'),
                $s['policyNumber'] ?? '—',
                $s['stateCode'] ?? '—',
                isset($s['createDate']) ? Carbon::parse($s['createDate'])->format('m/d/Y') : '—',
            ])->toArray()
        );

        // ── Match to our workers comp docs ───────────────────────────────
        $docs = VendorDoc::withoutGlobalScopes()
            ->where('type', 'workers')
            ->when($this->option('belongs-to-vendor-id'), fn ($q, $id) => $q->where('belongs_to_vendor_id', $id))
            ->with('vendor')
            ->get();

        $matched = 0;
        $unmatched = [];

        foreach ($subscriptions as $sub) {
            $doc = $docs->first(fn (VendorDoc $d) => $this->policiesMatch($d->number, $sub['policyNumber'] ?? null));

            if (! $doc) {
                $unmatched[] = ($sub['employerInformation']['employer'] ?? '?').' ('.($sub['policyNumber'] ?? '?').')';

                continue;
            }

            if ($this->option('dry-run')) {
                $this->line("    would stamp: {$doc->vendor?->business_name} ← {$sub['policyNumber']}");
                $matched++;

                continue;
            }

            $options = $doc->options ?? [];
            $options['ewccv'] = [
                'status' => 'tracking',
                'source' => 'subscription-sync',
                'policy_subscription_id' => $sub['policySubscriptionId'] ?? null,
                'written_policy_id' => $sub['writtenPolicyId'] ?? null,
                'state_code' => $sub['stateCode'] ?? null,
                'tracking_since' => $sub['createDate'] ?? null,
                'checked_at' => now()->toIso8601String(),
            ];
            $doc->options = $options;
            // saveQuietly: VendorDocObserver re-queues an EWCCV lookup whenever
            // a doc changes, and recording a lookup's own result must not loop.
            $doc->saveQuietly();
            $matched++;
        }

        $this->newLine();
        $this->info("  Stamped {$matched} vendor doc(s) as EWCCV-tracked.");

        if ($unmatched) {
            $this->warn('  Tracked at EWCCV but not matched to a vendor doc:');
            foreach ($unmatched as $u) {
                $this->line("    • {$u}");
            }
        }

        // Which of our policies EWCCV is NOT watching — the actual gap to close.
        $untracked = $docs->filter(function (VendorDoc $d) use ($subscriptions) {
            return ! $subscriptions->contains(fn ($s) => $this->policiesMatch($d->number, $s['policyNumber'] ?? null));
        });

        if ($untracked->isNotEmpty()) {
            $this->newLine();
            $this->warn("  Not tracked by EWCCV ({$untracked->count()}):");
            foreach ($untracked as $d) {
                $this->line('    • '.($d->vendor?->business_name ?? "vendor {$d->vendor_id}")." — {$d->number}");
            }
        }

        Log::info('EWCCV subscriptions synced', [
            'tracked' => $subscriptions->count(),
            'matched' => $matched,
            'untracked' => $untracked->count(),
        ]);

        return self::SUCCESS;
    }

    /**
     * Policy numbers are written inconsistently between our records and the
     * carrier feed ("WC5-39S-342438-046" vs "WC539S342438046"), so compare on
     * alphanumerics only.
     */
    private function policiesMatch(?string $ours, ?string $theirs): bool
    {
        if (! $ours || ! $theirs) {
            return false;
        }

        $norm = fn (string $v) => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $v));

        return $norm($ours) !== '' && $norm($ours) === $norm($theirs);
    }

    /** EWCCV drops sign-in emails requested via the residential proxy. */
    private function directNetworkEnv(): array
    {
        return [
            'EWCCV_PROXY_HOST' => '',
            'EWCCV_PROXY_PORT' => '',
            'EWCCV_PROXY_USER' => '',
            'EWCCV_PROXY_PASS' => '',
        ];
    }

    private function cachedLoginUrl(string $outputDir): ?string
    {
        $file = $outputDir.'/.last_login_url.json';

        if (! file_exists($file)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($file), true);

        if (empty($data['url']) || empty($data['cached_at'])) {
            return null;
        }

        // Sign-in links are single-use and short-lived; only reuse a very fresh one.
        if (Carbon::parse($data['cached_at'])->diffInMinutes(now()) > 10) {
            return null;
        }

        $this->line('  Reusing a recent sign-in link.');

        return $data['url'];
    }

    private function clearCachedLoginUrl(string $outputDir): void
    {
        @unlink($outputDir.'/.last_login_url.json');
    }

    private function fetchLoginUrl(NylasService $nylasService, string $outputDir): ?string
    {
        $email = $this->option('login-email');
        $requestedAfter = now()->subMinute();

        $this->line("  Requesting a sign-in link for {$email}…");

        $configFile = tempnam(sys_get_temp_dir(), 'ewccv_mail_');
        file_put_contents($configFile, json_encode([
            'mode' => 'request-email',
            'loginEmail' => $email,
            'outputDir' => $outputDir,
            'screenshotDir' => public_path('EWCCV'),
            'headless' => true,
            'delayMs' => 3000,
            'captchaApiKey' => '',
            'twoCaptchaKey' => '',
        ]));

        $result = Process::timeout(180)
            ->env($this->directNetworkEnv())
            ->run(sprintf(
                '%s %s %s',
                trim((string) shell_exec('which node')) ?: 'node',
                base_path('scripts/ewccv-scraper.cjs'),
                $configFile
            ));

        @unlink($configFile);

        $sent = json_decode(trim($result->output()), true);

        if (empty($sent['emailSent'])) {
            $this->error('  EWCCV did not confirm the sign-in email was sent.');

            return null;
        }

        $this->line('  Sent. Polling for it…');

        $grantId = config('nylas.certificates_grant_id');

        for ($attempt = 1; $attempt <= 20; $attempt++) {
            sleep(15);

            try {
                $messages = $nylasService->getMessages($grantId, [
                    'limit' => 15,
                    'received_after' => $requestedAfter->timestamp,
                ]);

                foreach ($messages['data'] ?? [] as $message) {
                    if (stripos((string) ($message['subject'] ?? ''), 'Sign in to Workers Compensation') === false) {
                        continue;
                    }

                    if (preg_match('#https://www\.ewccv\.com/cvs/login/\?t=[A-Za-z0-9._-]+#', (string) ($message['body'] ?? ''), $m)) {
                        $this->info("  Got the link (attempt {$attempt}).");
                        file_put_contents($outputDir.'/.last_login_url.json', json_encode([
                            'url' => $m[0],
                            'cached_at' => now()->toIso8601String(),
                        ]));

                        return $m[0];
                    }
                }
            } catch (\Throwable $e) {
                $this->warn("    mailbox poll failed: {$e->getMessage()}");
            }
        }

        $this->error('  Timed out waiting for the sign-in email.');

        return null;
    }
}
