<?php

namespace App\Console\Commands;

use App\Services\MenardsRemoteBrowserService;
use Illuminate\Console\Command;

/**
 * Lifecycle for the server-side signed-in Chromium that the receipt extension
 * runs inside. `start` once, sign in over noVNC once, and the extension's alarm
 * handles every run after that.
 */
class MenardsBrowser extends Command
{
    protected $signature = 'menards:browser {action=status : start|stop|status|check}
        {--reset-profile : Wipe the browser profile — this signs you out}';

    protected $description = 'Manage the server-side signed-in browser used to sync Menards receipts';

    public function handle(MenardsRemoteBrowserService $browser): int
    {
        return match ($this->argument('action')) {
            'check' => $this->check($browser),
            'start' => $this->start($browser),
            'stop' => $this->stop($browser),
            default => $this->status($browser),
        };
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
