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
     * @param  array{coverage_date?: string, verify_only?: bool, checks?: array<string, list<int>>}  $options  verify_only + coverage_date: every vendor is an as-of check; checks: coverage checks (vendor ids by date) run alongside the tracking lookups
     * @return int|null the new process id, null when it could not be started
     */
    public function launch(array $vendorIds, int $belongsToVendorId, array $options = []): ?int
    {
        $pid = (int) trim((string) shell_exec(self::commandLine($vendorIds, $belongsToVendorId, $options)));
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
     * @param  array{coverage_date?: string, verify_only?: bool, checks?: array<string, list<int>>}  $options
     */
    public static function commandLine(array $vendorIds, int $belongsToVendorId, array $options = []): string
    {
        $checks = [];
        foreach ((array) ($options['checks'] ?? []) as $date => $ids) {
            foreach ((array) $ids as $id) {
                $checks[] = '--check='.(int) $id.':'.$date;
            }
        }

        $command = implode(' ', array_map('escapeshellarg', array_values(array_filter([
            PHP_BINARY,
            base_path('artisan'),
            'ewccv:scrape-tracking',
            '--belongs-to-vendor-id='.$belongsToVendorId,
            ...array_map(fn (int $id) => '--vendor-id='.$id, $vendorIds),
            ...$checks,
            filled($options['coverage_date'] ?? null) ? '--coverage-date='.$options['coverage_date'] : null,
            ! empty($options['verify_only']) ? '--verify-only' : null,
        ]))));

        return sprintf('nohup setsid %s >> %s 2>&1 < /dev/null & echo $!', $command, escapeshellarg(storage_path(self::LOG)));
    }
}
