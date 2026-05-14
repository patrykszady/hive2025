<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Weekly maintenance: prune old failed_jobs rows and OPTIMIZE the table.
 *
 * Replaces an inline Schedule::call closure so the work runs on a Horizon
 * worker instead of blocking the schedule:run process.
 */
class PruneFailedJobsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct()
    {
        $this->onQueue('background');
    }

    public function handle(): void
    {
        Artisan::call('queue:prune-failed', ['--hours' => 4320]);
        DB::statement('OPTIMIZE TABLE failed_jobs');
    }
}
