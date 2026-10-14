<?php

namespace App\Console\Commands;

use App\Services\EwccvRemoteBrowserService;
use Illuminate\Console\Command;

/**
 * Run the EWCCV server browser (EwccvRemoteBrowserService): a real Chrome on
 * the server that does workers comp lookups with nobody's own browser open.
 */
class EwccvBrowser extends Command
{
    protected $signature = 'ewccv:browser {action=status : start, stop, status or screenshot}';

    protected $description = 'Start, stop or check the server Chrome that runs EWCCV lookups';

    public function handle(EwccvRemoteBrowserService $browser): int
    {
        return match ($this->argument('action')) {
            'start' => $this->start($browser),
            'stop' => $this->done($browser->stop(), 'Stopped.'),
            'screenshot' => $this->screenshot($browser),
            'status' => $this->status($browser),
            default => $this->invalid(),
        };
    }

    protected function start(EwccvRemoteBrowserService $browser): int
    {
        $result = $browser->start();
        if (! $result['ok']) {
            $this->error($result['error'] ?? 'Could not start.');

            return self::FAILURE;
        }

        $this->info('EWCCV server browser started on display '.EwccvRemoteBrowserService::DISPLAY.' with the EWCCV extension loaded.');
        $this->line('To watch it from your machine:');
        $this->line('  ssh -L '.EwccvRemoteBrowserService::WS_PORT.':127.0.0.1:'.EwccvRemoteBrowserService::WS_PORT.' hive-prod');
        $this->line('  open '.$result['vnc_url']);

        return self::SUCCESS;
    }

    protected function status(EwccvRemoteBrowserService $browser): int
    {
        foreach ($browser->status() as $part => $up) {
            $this->line(sprintf('  %-22s %s', $part, $up ? 'yes' : 'no'));
        }

        return self::SUCCESS;
    }

    protected function screenshot(EwccvRemoteBrowserService $browser): int
    {
        $path = $browser->screenshot();
        $path ? $this->info("Saved {$path}") : $this->error('No screenshot (is the browser running?)');

        return $path ? self::SUCCESS : self::FAILURE;
    }

    protected function done(array $result, string $message): int
    {
        $this->info($message);

        return self::SUCCESS;
    }

    protected function invalid(): int
    {
        $this->error('Use start, stop, status or screenshot.');

        return self::FAILURE;
    }
}
