<?php

namespace App\Jobs;

use App\Models\MeetingRecording;
use App\Services\Meetings\MeetingSummarizer;
use App\Services\Meetings\MeetingTranscriber;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Asks AssemblyAI whether a meeting's transcript is ready — again every
 * EVERY_SECONDS until it is (a new job each time, so no worker waits) —
 * then summarizes it and sends its action items to the project's planner.
 */
class CheckMeetingTranscript implements ShouldQueue
{
    use Queueable;

    public const EVERY_SECONDS = 30;

    /** About an hour of asking before giving up. */
    public const MAX_CHECKS = 120;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(public int $meetingRecordingId, public int $check = 1)
    {
        $this->onQueue('default');
    }

    public function handle(MeetingTranscriber $transcriber, MeetingSummarizer $summarizer): void
    {
        $recording = MeetingRecording::find($this->meetingRecordingId);

        if (! $recording || $recording->status !== MeetingRecording::STATUS_PROCESSING || ! $recording->assemblyai_id) {
            return;
        }

        try {
            $body = $transcriber->fetch($recording->assemblyai_id);

            if ($body === null) {
                if ($this->check >= self::MAX_CHECKS) {
                    $recording->update(['status' => MeetingRecording::STATUS_FAILED, 'failure_reason' => 'transcription_timeout']);

                    return;
                }

                self::dispatch($recording->id, $this->check + 1)->delay(now()->addSeconds(self::EVERY_SECONDS));

                return;
            }

            if (($body['status'] ?? null) === 'error') {
                throw new \RuntimeException('assemblyai_error: '.($body['error'] ?? 'unknown'));
            }

            $lines = MeetingTranscriber::linesFrom($body);

            if ($lines['text'] === '') {
                $recording->update(['status' => MeetingRecording::STATUS_EMPTY, 'failure_reason' => 'empty_transcript', 'language' => $lines['language']]);

                return;
            }

            $recording->update(['transcript' => $lines['text'], 'segments' => $lines['segments'], 'language' => $lines['language']]);

            [$summary, $model] = $summarizer->summarize($recording);

            $recording->update([
                'status' => ($summary['recording_has_no_message'] ?? false) ? MeetingRecording::STATUS_EMPTY : MeetingRecording::STATUS_READY,
                'summary' => $summary['summary'] ?? null,
                'action_items' => array_values(array_filter((array) ($summary['action_items'] ?? []), 'is_string')),
                'topics' => (array) ($summary['topics'] ?? []),
                'next_steps' => (array) ($summary['next_steps'] ?? []),
                'summary_model' => $model,
                'summarized_at' => now(),
                'purge_after' => now()->addDays((int) config('meeting_recording.retention_days', 180)),
            ]);

            if ($recording->status === MeetingRecording::STATUS_READY && $recording->project_id && $recording->action_items) {
                ExtractProjectActionItems::dispatch($recording->project_id);
            }
        } catch (\Throwable $e) {
            Log::channel('call_ai')->error('Meeting transcript could not be read', ['meeting_recording_id' => $recording->id, 'error' => $e->getMessage()]);
            $recording->update(['status' => MeetingRecording::STATUS_FAILED, 'failure_reason' => mb_substr($e->getMessage(), 0, 500)]);
        }
    }
}
