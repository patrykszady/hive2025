<?php

namespace App\Support\Ewccv;

use Illuminate\Support\Facades\Log;

/**
 * Starts an EWCCV scraper run as a process of its own, detached from the
 * queue worker that asked for it.
 *
 * A run searches EWCCV for up to a dozen vendors and takes anything from
 * four to thirty minutes. Run inside a Horizon worker it held that worker
 * for the duration, and a deploy's horizon:terminate waits for every worker
 * to finish before Horizon restarts: on 2026-10-12 a deploy during a run
 * left every other queue without workers for the length of the run, with
 * eighteen default-queue jobs (texts, reminders) waiting. Detached under its
 * own session, the run outlives the worker, the deploy and a Horizon
 * restart, and the job that starts it is done in milliseconds.
 *
 * Overlap is prevented by the command itself (the `ewccv:scraper` lock),
 * and its output goes to storage/logs/ewccv-scraper.log like the scheduled
 * EWCCV commands'.
 */
class ScrapeLauncher
{
    public const LOG = 'logs/ewccv-scraper.log';

    /**
     * @param  list<int>  $vendorIds
     * @return int|null the new process id, null when it could not be started
     */
    public function launch(array $vendorIds, int $belongsToVendorId): ?int
    {
        $pid = (int) trim((string) shell_exec(self::commandLine($vendorIds, $belongsToVendorId)));
        if ($pid <= 0) {
            Log::warning('EWCCV scraper could not be started detached', ['vendor_ids' => $vendorIds]);

            return null;
        }

        return $pid;
    }

    /**
     * The shell line that starts the run in its own session (setsid), immune
     * to the worker's hang-up (nohup), with its output appended to the log,
     * and prints the new process id.
     *
     * @param  list<int>  $vendorIds
     */
    public static function commandLine(array $vendorIds, int $belongsToVendorId): string
    {
        $command = implode(' ', array_map('escapeshellarg', [
            PHP_BINARY,
            base_path('artisan'),
            'ewccv:scrape-tracking',
            '--belongs-to-vendor-id='.$belongsToVendorId,
            ...array_map(fn (int $id) => '--vendor-id='.$id, $vendorIds),
        ]));

        return sprintf('nohup setsid %s >> %s 2>&1 < /dev/null & echo $!', $command, escapeshellarg(storage_path(self::LOG)));
    }
}
