<?php

namespace App\Console\Commands;

use App\Models\CallLog;
use App\Models\CallTranscript;
use Illuminate\Console\Command;

class PurgeJunkCallRecordings extends Command
{
    protected $signature = 'calls:purge-junk-recordings
        {--execute : Actually purge (default is a dry-run preview)}
        {--ids= : Comma-separated call log IDs to purge instead of the built-in list}
    ';

    protected $description = 'Purge recordings/transcripts for calls that captured no real message (IVR prompts, voicemail greetings, empty/nonsense audio).';

    /**
     * Call log IDs identified as junk recordings during the June 2026 cleanup.
     * These are stable primary keys shared between the local copy and production.
     *
     * @var array<int, int>
     */
    private const JUNK_CALL_IDS = [
        // Outbound reached target voicemail / nonsense / IVR (reviewed individually)
        1455, 1450, 1446, 1445, 1444,
        // Empty transcripts
        1430, 1403, 1372, 1349, 1324,
        // Outbound voicemail-greeting only (no message left)
        1401, 1387, 1376, 1364, 1354, 1352, 1315,
        // IVR / system-prompt only
        1374, 1336,
        // Nonsense fragments
        1416, 1415, 1351, 1452,
        // Ambiguous (approved for purge)
        1454, 1333, 1318,
        // Spam robocalls
        1332, 1328,
        // Greeting/IVR-only recordings (caller hung up during our greeting,
        // or outbound reached the target's voicemail greeting/IVR menu)
        1457, 1434, 1405, 1428, 1407, 1398, 1396, 1362, 1360, 1347, 1346, 1343, 1330,
    ];

    public function handle(): int
    {
        $execute = (bool) $this->option('execute');

        $ids = $this->resolveIds();
        if ($ids === []) {
            $this->error('No call IDs to process.');

            return self::FAILURE;
        }

        $this->info(($execute ? 'EXECUTING' : 'DRY-RUN') . ' purge for ' . count($ids) . ' call(s)');
        $this->newLine();

        $purged = 0;
        $skipped = 0;
        $missing = 0;

        foreach ($ids as $id) {
            $call = CallLog::find($id);

            if (! $call) {
                $this->line("  #{$id}: <fg=yellow>not found</> (skipped)");
                $missing++;

                continue;
            }

            if (! $call->recording_path && ! $call->recording_url) {
                $this->line("  #{$id}: already clean (no recording) — skipped");
                $skipped++;

                continue;
            }

            $transcript = CallTranscript::where('call_log_id', $id)->first();
            $snippet = $transcript
                ? str_replace("\n", ' ', mb_substr((string) $transcript->text, 0, 70))
                : '(no transcript)';

            $this->line("  #{$id}: {$call->direction} | {$call->status} | vm=" . ($call->has_voicemail ? '1' : '0') . " | \"{$snippet}\"");

            if ($execute) {
                $call->purgeRecording();
            }

            $purged++;
        }

        $this->newLine();
        $verb = $execute ? 'Purged' : 'Would purge';
        $this->info("{$verb}: {$purged} | already clean: {$skipped} | not found: {$missing}");

        if (! $execute) {
            $this->newLine();
            $this->comment('Dry-run only. Re-run with --execute to apply.');
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, int>
     */
    private function resolveIds(): array
    {
        $option = $this->option('ids');

        if (is_string($option) && trim($option) !== '') {
            return collect(explode(',', $option))
                ->map(static fn (string $id): int => (int) trim($id))
                ->filter(static fn (int $id): bool => $id > 0)
                ->unique()
                ->values()
                ->all();
        }

        return self::JUNK_CALL_IDS;
    }
}
