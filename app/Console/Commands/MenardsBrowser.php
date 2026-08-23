<?php

namespace App\Console\Commands;

use App\Jobs\NotifyMenardsBrowserNeedsAttention;
use App\Services\MenardsRemoteBrowserService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Console\Command;

/**
 * Lifecycle for the server-side signed-in Chromium that the receipt extension
 * runs inside. `start` once, sign in over noVNC once, and the extension's alarm
 * handles every run after that.
 */
class MenardsBrowser extends Command
{
    protected $signature = 'menards:browser {action=status : start|stop|status|check|login|ensure}
        {--reset-profile : Wipe the browser profile — this signs you out}';

    protected $description = 'Manage the server-side signed-in browser used to sync Menards receipts';

    public function handle(MenardsRemoteBrowserService $browser): int
    {
        return match ($this->argument('action')) {
            'check' => $this->check($browser),
            'login' => $this->login($browser),
            'ensure' => $this->ensure($browser),
            'start' => $this->start($browser),
            'stop' => $this->stop($browser),
            default => $this->status($browser),
        };
    }

    /**
     * Make everything about the receipt browser true, idempotently.
     *
     * This is the whole lifecycle as one command: repack the extension if its
     * source changed, (re)start the stack if it is down / unconfigured / stale,
     * sign in if the session lapsed, then report. Safe to run any time — the
     * deploy script runs it after every deploy and the scheduler runs it hourly,
     * which is what makes the arrangement self-healing: a rebooted server or an
     * expired session repairs itself within the hour instead of failing silently
     * for two weeks the way the Puppeteer scraper did.
     *
     * The hourly run can, at worst, land during the extension's once-daily sync
     * and navigate the tab out from under it. That costs nothing durable: the
     * sync posts its receipts in one request at the end, so an interrupted run
     * posts nothing, and the next day's run re-covers the window (14-day
     * lookback, idempotent import).
     */
    protected function ensure(MenardsRemoteBrowserService $browser): int
    {
        // One ensure at a time, whoever invoked it. The scheduler's
        // withoutOverlapping() only guards scheduler-vs-scheduler; a deploy-time
        // run and the hourly run would otherwise drive the same display at
        // once — two xdotool typists sharing one omnibox, one of them feeding
        // the other's password into a Google search. The 15-minute TTL bounds a
        // stale lock from a mid-run crash to one skipped cycle.
        $lock = \Illuminate\Support\Facades\Cache::lock('menards-browser-ensure', 900);

        if (! $lock->get()) {
            $this->info('Another ensure run is already in progress — leaving it to finish.');

            return self::SUCCESS;
        }

        try {
            return $this->ensureLocked($browser);
        } finally {
            $lock->release();
        }
    }

    protected function ensureLocked(MenardsRemoteBrowserService $browser): int
    {
        $req = $browser->checkRequirements();

        if (! $req['ok']) {
            $this->error('Missing host requirements: ' . implode(', ', $req['missing']));
            $this->line('Run once: bash scripts/provision-menards-browser.sh');

            return self::FAILURE;
        }

        // Config first, repack second — the order is load-bearing. The packed
        // extension reads defaults.json from INSIDE its own package (that is
        // where chrome.runtime.getURL resolves for an installed extension), so
        // the file must exist in the source tree before packing stages it, and
        // a change to it must count as a source change so rotation repacks.
        $browser->writeExtensionDefaults();

        // Repack the policy-installed extension if its source changed. The
        // marker line is the contract: "unavailable" is a box that has never
        // been fully provisioned (a dev machine using Load unpacked) — fine;
        // "failed" or NO marker at all (the script died mid-pack) is a real
        // problem, reported but not fatal to the rest of the pass — the browser
        // itself is still worth keeping alive with a stale extension.
        $repack = (string) shell_exec(
            'bash ' . escapeshellarg(base_path('scripts/provision-menards-browser.sh')) . ' repack 2>&1'
        );
        $repackChanged = str_contains($repack, 'REPACK: changed');
        $repackFailed = ! preg_match('/^REPACK: (changed|unchanged|unavailable)$/m', $repack);

        if ($repackChanged) {
            $this->line('Extension source changed — repacked; the restart below installs it.');
        }

        if ($repackFailed) {
            $this->warn('Extension repack FAILED — the browser keeps running with the previous pack.');
            $this->line(trim($repack) ?: '(no output from the repack script)');
            \Illuminate\Support\Facades\Log::channel('menards')->error('Menards ensure: repack failed', [
                'output' => substr($repack, -500),
            ]);
        }

        $status = $browser->status();

        // 'extension' belongs in this gate: a running browser whose force-install
        // never took is only fixable by a restart (Chrome re-reads the policy at
        // startup), and leaving it out meant ensure reported that failure every
        // hour without ever attempting its own remedy.
        if (! $status['running'] || ! $status['chrome'] || ! $status['extension'] || ! $status['configured'] || $repackChanged) {
            $this->line('Starting the browser…');
            $result = $browser->start();

            if (! ($result['ok'] ?? false)) {
                $this->error($result['error'] ?? 'Failed to start.');

                return self::FAILURE;
            }

            // Chrome needs a moment past process-up to apply the force-install
            // policy and boot the extension before status can see either.
            sleep(10);
        }

        // Proof beats probing. A receipt batch that arrived in the last day was
        // fetched BY the extension THROUGH this session, so the session works —
        // there is nothing login() could usefully verify, and verifying it costs
        // a navigation, which is the single thing most likely to draw Imperva's
        // challenge and destroy the session we just proved good.
        if ($this->recentBatchArrived()) {
            $this->line('A receipt batch arrived within the last day — the session works; not touching the browser.');
        } elseif ($this->login($browser) !== self::SUCCESS) {
            return self::FAILURE;
        }

        $this->warnIfNoRecentBatches();

        $final = $browser->status();
        $this->printStatus($final);

        $healthy = $final['running'] && $final['chrome'] && $final['extension']
            && $final['configured'] && $final['signed_in'] && ! $repackFailed;

        // Tell someone. The sync is unattended right up until Imperva asks for a
        // human, and a wall that waits in silence is how the last outage lasted
        // two weeks. Throttled to one notification per reason per 12 hours by
        // the job itself, so a schedule that runs all day says this once.
        if (! $healthy) {
            $this->notifyAttention($final, $repackFailed);
        }

        return $healthy ? self::SUCCESS : self::FAILURE;
    }

    /**
     * The failure mode that actually bit: everything looks healthy and nothing
     * arrives. The extension only POSTs when it finds receipts, so a quiet week
     * can be real — but at this company a week without a Menards purchase is
     * rare enough that a stale ingest directory is worth a loud line in the log
     * someone can alert on, where the old scraper's two-week silent gap was not.
     */
    protected function warnIfNoRecentBatches(): void
    {
        $dirs = glob(storage_path('files/_menards_ingest/*'), GLOB_ONLYDIR) ?: [];

        if ($dirs === []) {
            return; // freshly provisioned — nothing has ever arrived, nothing is "late"
        }

        $newest = max(array_map('filemtime', $dirs));

        if ($newest < now()->subDays(7)->getTimestamp()) {
            // Plain arithmetic on purpose: Carbon 3's diffInDays() is a signed
            // float (target minus source), which printed "-9.3 days" here.
            $days = intdiv(now()->getTimestamp() - $newest, 86400);
            $this->warn("No receipt batch has arrived in {$days} days — the sync may be failing silently.");
            \Illuminate\Support\Facades\Log::channel('menards')->error('Menards ensure: no ingest batch in ' . $days . ' days');
        }
    }

    /**
     * Did the extension deliver anything in the last 25 hours?
     *
     * 25 rather than 24 so a daily run that drifts by a few minutes does not
     * conclude the sync is broken when yesterday's batch is 24h01m old.
     */
    protected function recentBatchArrived(): bool
    {
        $dir = storage_path('files/_menards_ingest');

        if (! is_dir($dir)) {
            return false;
        }

        $cutoff = now()->subHours(25)->getTimestamp();

        foreach (glob($dir . '/*', GLOB_ONLYDIR) ?: [] as $batch) {
            if (filemtime($batch) >= $cutoff) {
                return true;
            }
        }

        return false;
    }

    /**
     * Pick the single most useful thing to say, and say only that.
     *
     * Ordered by what blocks what: a browser that is down explains everything
     * below it, and a missing extension means no amount of clicking will help.
     * Sending one notification per fault would bury the actionable one.
     */
    protected function notifyAttention(array $status, bool $repackFailed): void
    {
        [$reason, $detail] = match (true) {
            ! $status['running'] || ! $status['chrome'] => [
                'down',
                'The browser on the server is not running, so no receipts can be fetched.',
            ],
            ! $status['extension'] => [
                'extension_missing',
                'The receipt extension is not installed — the browser runs but nothing will ever sync.',
            ],
            ! $status['configured'] => [
                'extension_missing',
                'The extension has no Hive URL or token, so it cannot deliver receipts.',
            ],
            $this->isWalled($status) => [
                'wall',
                'Menards is showing an "I am human" check. Open this and click it — sign-in continues on its own.',
            ],
            ! $status['signed_in'] => [
                'signed_out',
                'The browser is signed out of menards.com and could not sign back in.',
            ],
            default => [
                'attention',
                'The Menards receipt browser needs a look.',
            ],
        };

        NotifyMenardsBrowserNeedsAttention::dispatch($reason, $detail);

        $this->line("Notified admins: {$detail}");
    }

    /**
     * Every real Menards page titles itself "… at Menards®"; the Imperva
     * interstitial sets no title at all, so Chrome shows the bare URL.
     */
    protected function isWalled(array $status): bool
    {
        $page = $status['page'] ?? '';

        return str_contains($page, 'menards.com/') && ! str_contains($page, 'at Menards');
    }

    /**
     * Sign in using the credentials already stored for the Menards receipt
     * account — the same row the old Puppeteer scraper read.
     *
     * This is what keeps the arrangement unattended. The browser holds a session
     * a person established only until Menards expires it; without this the next
     * expiry means someone tunnels in over noVNC and types a password.
     */
    protected function login(MenardsRemoteBrowserService $browser): int
    {
        [$email, $password] = $this->credentials();

        if (! $email || ! $password) {
            $this->error('No Menards credentials found in receipt_accounts.options.');

            return self::FAILURE;
        }

        $this->line("Signing in as {$email}…");

        $result = $browser->login($email, $password);

        if (! $result['ok']) {
            $this->error($result['error'] ?? 'Sign-in failed.');

            return self::FAILURE;
        }

        if ($result['already'] ?? false) {
            $this->info('Already signed in — nothing to do.');

            return self::SUCCESS;
        }

        $this->info('Signed in — now on: ' . ($result['url'] ?? '?'));

        return self::SUCCESS;
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    protected function credentials(): array
    {
        foreach (DB::table('receipt_accounts')->get() as $row) {
            $options = json_decode($row->options ?? '{}', true) ?: [];

            if (empty($options['email']) || empty($options['password'])) {
                continue;
            }

            try {
                return [$options['email'], Crypt::decryptString($options['password'])];
            } catch (\Throwable $e) {
                // Encrypted under a different APP_KEY — a dev copy of the
                // production database without the production key. Say so rather
                // than handing Menards a ciphertext and reporting bad credentials.
                $this->error("Receipt account #{$row->id}: password will not decrypt with this APP_KEY.");

                return [null, null];
            }
        }

        return [null, null];
    }

    protected function check(MenardsRemoteBrowserService $browser): int
    {
        $req = $browser->checkRequirements();

        if ($req['ok']) {
            $this->info('All host requirements present.');

            return self::SUCCESS;
        }

        $this->error('Missing: ' . implode(', ', $req['missing']));
        $this->line('  sudo apt install xvfb x11vnc novnc websockify chromium-browser');

        return self::FAILURE;
    }

    protected function start(MenardsRemoteBrowserService $browser): int
    {
        $result = $browser->start((bool) $this->option('reset-profile'));

        if (! ($result['ok'] ?? false)) {
            $this->error($result['error'] ?? 'Failed to start.');

            return self::FAILURE;
        }

        $this->info('Browser started.');

        $postsTo = $browser->postsTo();
        $this->line('  extension posts to: ' . ($postsTo ?: '(unconfigured — set MENARDS_BRIDGE_TOKEN)'));
        $this->newLine();

        // The 127.0.0.1 below is correct, not a placeholder: the ssh tunnel maps
        // your machine's loopback onto this server's, and these ports are bound
        // to loopback on purpose — they must never be public.
        $address = trim((string) shell_exec("hostname -I 2>/dev/null | awk '{print \$1}'")) ?: gethostname();
        $user = get_current_user() ?: 'forge';
        $this->line('  To watch it (optional):');
        $this->line("  ssh -L 6098:127.0.0.1:6098 {$user}@{$address}");
        $this->line('  ' . $result['vnc_url']);

        return self::SUCCESS;
    }

    protected function stop(MenardsRemoteBrowserService $browser): int
    {
        $browser->stop();
        $this->info('Stopped.');

        return self::SUCCESS;
    }

    protected function status(MenardsRemoteBrowserService $browser): int
    {
        $this->printStatus($browser->status());

        return self::SUCCESS;
    }

    protected function printStatus(array $status): void
    {
        foreach ($status as $k => $v) {
            // 'page' and 'posts_to' are strings, not flags — printing them as
            // yes/no would throw away exactly the detail they exist to show.
            $this->line(sprintf('  %-10s %s', $k, is_bool($v) ? ($v ? 'yes' : 'no') : ($v ?: '—')));
        }

        if ($status['running'] && ! $status['extension']) {
            $this->newLine();
            $this->warn('The receipt extension is not loaded — nothing will sync.');
            $this->line('Run scripts/provision-menards-browser.sh, then: php artisan menards:browser ensure');
        }

        if (! $status['configured']) {
            $this->newLine();
            $this->warn('The extension has no Hive URL or token — it cannot deliver receipts.');
            $this->line('Set MENARDS_BRIDGE_TOKEN in the environment, then: php artisan menards:browser ensure');
        }
    }
}
