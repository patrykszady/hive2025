<?php

namespace App\Console\Commands;

use App\Models\CallLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

class PullCallRecordings extends Command
{
    protected $signature = 'calls:pull-recordings
        {--host= : SSH host or alias for production (default: HIVE_PROD_HOST env or "hive-prod")}
        {--remote-path= : Production app root (default: HIVE_PROD_PATH env or /home/forge/hive.contractors)}
        {--delete : Remove local recordings that no longer exist on production}
        {--dry-run : Show what would be transferred without copying anything}';

    protected $description = 'Rsync all call recording files from production into this environment (mirrors scripts/pull-prod-storage.sh, scoped to call recordings)';

    /**
     * Directory holding call recordings, relative to storage/app.
     * Matches CallLog.recording_path values like
     * "public/call-recordings/2026/07/<uuid>.mp3" on the "local" disk.
     */
    private const RECORDINGS_SUBPATH = 'storage/app/public/call-recordings/';

    public function handle(): int
    {
        $host = $this->option('host') ?: env('HIVE_PROD_HOST', 'hive-prod');
        $remoteRoot = rtrim($this->option('remote-path') ?: env('HIVE_PROD_PATH', '/home/forge/hive.contractors'), '/');

        $localDir = base_path(self::RECORDINGS_SUBPATH);
        if (! is_dir($localDir)) {
            mkdir($localDir, 0775, true);
        }

        $missingBefore = $this->missingRecordingCount();
        $this->info("Call logs referencing a recording file that is missing locally: {$missingBefore}");

        $args = ['rsync', '-avz', '--human-readable'];
        if ($this->option('delete')) {
            $args[] = '--delete';
        }
        if ($this->option('dry-run')) {
            $args[] = '--dry-run';
        }
        $args[] = "{$host}:{$remoteRoot}/" . self::RECORDINGS_SUBPATH;
        $args[] = $localDir;

        $this->line('→ ' . implode(' ', $args));
        $this->newLine();

        $result = Process::timeout(3600)->run($args, function (string $type, string $output) {
            // Stream rsync progress straight through.
            $this->getOutput()->write($output);
        });

        if (! $result->successful()) {
            $this->error("rsync failed (exit code {$result->exitCode()}).");
            if (str_contains($result->errorOutput(), 'Connection') || str_contains($result->errorOutput(), 'resolve')) {
                $this->line("Check that the '{$host}' SSH alias works: ssh {$host} true");
            }

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->info('Dry run complete — nothing copied.');

            return self::SUCCESS;
        }

        $missingAfter = $this->missingRecordingCount();
        $recovered = $missingBefore - $missingAfter;

        $this->newLine();
        $this->info("Done. Recovered {$recovered} missing recording(s); {$missingAfter} still missing locally.");

        if ($missingAfter > 0) {
            $this->line('Remaining gaps are usually recordings purged on production (silent calls) whose call_logs rows predate the purge.');
        }

        return self::SUCCESS;
    }

    /**
     * How many call_logs reference a recording file that does not exist on
     * the local disk.
     */
    protected function missingRecordingCount(): int
    {
        $missing = 0;

        CallLog::query()
            ->whereNotNull('recording_path')
            ->whereNotNull('recording_disk')
            ->select(['id', 'recording_disk', 'recording_path'])
            ->chunkById(200, function ($calls) use (&$missing): void {
                foreach ($calls as $call) {
                    try {
                        if (! Storage::disk($call->recording_disk)->exists($call->recording_path)) {
                            $missing++;
                        }
                    } catch (\Throwable) {
                        $missing++;
                    }
                }
            });

        return $missing;
    }
}
