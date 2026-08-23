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
    protected $signature = 'menards:browser {action=status : start|stop|status|check|login}
        {--reset-profile : Wipe the browser profile — this signs you out}';

    protected $description = 'Manage the server-side signed-in browser used to sync Menards receipts';

    public function handle(MenardsRemoteBrowserService $browser): int
    {
        return match ($this->argument('action')) {
            'check' => $this->check($browser),
            'login' => $this->login($browser),
            'start' => $this->start($browser),
            'stop' => $this->stop($browser),
            default => $this->status($browser),
        };
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
        $this->line('  Sign in here (tunnel the port, then open it):');
        $this->line('  ' . $result['vnc_url']);
        $this->newLine();
        $this->line('  ssh -L 6098:127.0.0.1:6098 forge@<server>   # then browse to the URL above');

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
        foreach ($browser->status() as $k => $v) {
            $this->line(sprintf('  %-10s %s', $k, $v ? 'yes' : 'no'));
        }

        return self::SUCCESS;
    }
}
