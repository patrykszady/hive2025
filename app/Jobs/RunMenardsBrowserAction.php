<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;

/**
 * A `menards:browser` run a person asked for from the viewer page (Retry
 * sign-in, Sync now, or the wall going away while they watched). Always
 * --manual: a person is behind it, so it signs in even while automatic
 * sign-ins are paused after an uncleared security check.
 */
class RunMenardsBrowserAction implements ShouldQueue
{
    use Queueable;

    /** @param  'ensure'|'sync'  $action */
    public function __construct(public string $action)
    {
        $this->onQueue('background');
    }

    public function handle(): void
    {
        Artisan::call('menards:browser', ['action' => $this->action, '--manual' => true]);
    }
}
