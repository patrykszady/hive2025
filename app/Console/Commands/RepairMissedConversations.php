<?php

namespace App\Console\Commands;

use App\Jobs\StoreCallRecording;
use App\Models\CallLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Inbound calls a team member took and then hung up first were played the
 * voicemail menu, filed as missed and had their recording thrown away
 * (14 calls 2026-09-25 to 10-16, e.g. Erin Abbey's five minutes on call
 * 2733). The webhook no longer does that; this puts those calls back:
 * answered (completed), and their recording fetched again from Telnyx,
 * which still keeps it. Transcription follows on the next
 * calls:process-recordings run.
 *
 * Re-runnable: only touches incoming calls still filed as missed that had a
 * conference with a team member in it (forwarded_to + conference_id; the
 * rollback of a leg that never joined clears both).
 */
class RepairMissedConversations extends Command
{
    protected $signature = 'calls:repair-missed-conversations {--dry-run : List the calls without changing them}';

    protected $description = 'File inbound calls a team member took and hung up first as answered, and fetch their recordings from Telnyx again';

    public function handle(): int
    {
        $calls = CallLog::withoutGlobalScopes()
            ->where('direction', 'incoming')
            ->where('status', CallLog::STATUS_MISSED)
            ->whereNotNull('forwarded_to')
            ->whereNotNull('metadata->conference_id')
            ->orderBy('id')
            ->get();

        $dryRun = (bool) $this->option('dry-run');
        $recordings = 0;

        foreach ($calls as $call) {
            $seconds = $call->answered_at && $call->ended_at ? (int) $call->answered_at->diffInSeconds($call->ended_at, true) : null;
            $recording = $call->recording_url ? null : $this->telnyxRecordingFor($call);

            $this->line(sprintf(
                '#%d %s %s, %ss — %s',
                $call->id,
                $call->created_at?->toDateTimeString(),
                $call->caller_name ?: $call->from_number,
                $seconds ?? '?',
                $call->recording_url ? 'has a recording' : ($recording ? 'recording found on Telnyx' : 'no recording on Telnyx'),
            ));

            if ($dryRun) {
                continue;
            }

            $call->update(array_filter([
                'status' => CallLog::STATUS_COMPLETED,
                'duration_seconds' => $call->duration_seconds ?: $seconds,
                'recording_url' => $recording['url'] ?? null,
                'recording_telnyx_id' => $recording['id'] ?? null,
            ], fn ($value) => $value !== null));

            if ($recording) {
                StoreCallRecording::dispatch($call->id);
                $recordings++;
            }
        }

        $this->info(($dryRun ? 'Would file ' : 'Filed ').$calls->count().' call(s) as answered'.($dryRun ? '' : "; {$recordings} recording(s) queued to store").'.');

        return self::SUCCESS;
    }

    /**
     * The recording Telnyx made of this call's caller leg, with a fresh
     * download link (they expire within minutes).
     *
     * @return array{id: string, url: string}|null
     */
    protected function telnyxRecordingFor(CallLog $call): ?array
    {
        $apiKey = config('services.telnyx.api_key');
        if (! $apiKey || ! $call->call_control_id || ! $call->created_at) {
            return null;
        }

        $response = Http::timeout(15)->withToken($apiKey)->get('https://api.telnyx.com/v2/recordings', [
            'filter[created_at][gte]' => $call->created_at->copy()->subMinute()->toIso8601ZuluString(),
            'filter[created_at][lte]' => ($call->ended_at ?? $call->created_at)->copy()->addMinutes(15)->toIso8601ZuluString(),
            'page[size]' => 50,
        ]);

        $recording = collect($response->successful() ? $response->json('data', []) : [])
            ->first(fn (array $recording) => ($recording['call_control_id'] ?? null) === $call->call_control_id);

        $url = $recording['download_urls']['wav'] ?? $recording['download_urls']['mp3'] ?? null;

        return $recording && $url ? ['id' => (string) $recording['id'], 'url' => $url] : null;
    }
}
