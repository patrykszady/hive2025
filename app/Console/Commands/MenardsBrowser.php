<?php

namespace App\Console\Commands;

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
        $req = $browser->checkRequirements();

        if (! $req['ok']) {
            $this->error('Missing host requirements: ' . implode(', ', $req['missing']));
            $this->line('Run once: bash scripts/provision-menards-browser.sh');

            return self::FAILURE;
        }

        // Repack the policy-installed extension if its source changed. Prints
        // "REPACK: unavailable" on a box that has never been fully provisioned
        // (a dev machine using Load unpacked) — not an error here.
        $repack = (string) shell_exec(
            'bash ' . escapeshellarg(base_path('scripts/provision-menards-browser.sh')) . ' repack 2>&1'
        );
        $repackChanged = str_contains($repack, 'REPACK: changed');

        if ($repackChanged) {
            $this->line('Extension source changed — repacked; the restart below installs it.');
        }

        $status = $browser->status();

        if (! $status['running'] || ! $status['chrome'] || ! $status['configured'] || $repackChanged) {
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

        if ($this->login($browser) !== self::SUCCESS) {
            return self::FAILURE;
        }

        $this->warnIfNoRecentBatches();

        $final = $browser->status();
        $this->printStatus($final);

        $healthy = $final['running'] && $final['chrome'] && $final['extension']
            && $final['configured'] && $final['signed_in'];

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
            $days = now()->diffInDays(\Carbon\Carbon::createFromTimestamp($newest));
            $this->warn("No receipt batch has arrived in {$days} days — the sync may be failing silently.");
            \Illuminate\Support\Facades\Log::channel('menards')->error('Menards ensure: no ingest batch in ' . $days . ' days');
        }
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
