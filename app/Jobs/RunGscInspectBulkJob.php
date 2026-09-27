<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Artisan;

/**
 * Runs the full-sitemap URL Inspection sweep from the queue. Ported from
 * dawnsellshomes' identical job. GscErrorController::refresh() wraps the
 * dispatch() call in try/catch, so this endpoint always returns a clean
 * response rather than a 500 regardless of the queue's state — but the
 * intended way to exercise this endpoint in tests is Queue::fake(), never
 * a live dispatch.
 */
class RunGscInspectBulkJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Full sweep is roughly 2s per URL across the whole sitemap. */
    public int $timeout = 3600;

    /** Never re-run a half-finished sweep automatically. */
    public int $tries = 1;

    public function handle(): void
    {
        // Same detached-process fallback as RunSeoChannelSyncJob — see its
        // docblock for why a queue worker cannot be assumed to be running.
        if (config('queue.default') === 'sync' && ! app()->runningUnitTests()) {
            RunSeoChannelSyncJob::dispatch('seo:gsc-inspect-bulk', ['--limit' => 0, '--markdown' => true]);

            return;
        }

        Artisan::call('seo:gsc-inspect-bulk', [
            '--limit' => 0,
            '--markdown' => true,
        ]);
    }
}
