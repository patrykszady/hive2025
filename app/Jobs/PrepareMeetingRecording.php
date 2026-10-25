<?php

namespace App\Jobs;

use App\Models\MeetingRecording;
use App\Services\Meetings\MeetingTranscriber;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * A meeting recording just stopped: join the pieces the browser uploaded
 * (in order — MediaRecorder slices of one recording join byte for byte),
 * compress them to mono MP3 with ffmpeg (which also repairs the duration a
 * browser recording leaves out), hand the file to AssemblyAI and leave
 * CheckMeetingTranscript to collect it — this job never waits on the
 * transcription, so it never holds a worker (or a deploy) for minutes.
 */
class PrepareMeetingRecording implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    /** Shorter than this, nothing worth reading was said. */
    public const MIN_SECONDS = 5;

    public function __construct(public int $meetingRecordingId)
    {
        $this->onQueue(config('meeting_recording.queue', 'long-running'));
    }

    public function handle(MeetingTranscriber $transcriber): void
    {
        $recording = MeetingRecording::find($this->meetingRecordingId);

        if (! $recording || $recording->status !== MeetingRecording::STATUS_PROCESSING) {
            return;
        }

        $disk = Storage::disk($recording->audio_disk ?: config('meeting_recording.disk'));
        $chunks = collect($disk->files($recording->chunkDirectory()))->sort()->values();

        if ($chunks->isEmpty()) {
            $recording->update(['status' => MeetingRecording::STATUS_EMPTY, 'failure_reason' => 'no_audio_uploaded']);

            return;
        }

        $base = 'meeting-recordings/'.$recording->id;
        $joined = $disk->path($base.'/joined.'.$recording->audioExtension());
        $mp3Path = $base.'/meeting.mp3';

        try {
            $out = fopen($joined, 'wb');
            foreach ($chunks as $chunk) {
                fwrite($out, (string) $disk->get($chunk));
            }
            fclose($out);

            $ffmpeg = Process::timeout(540)->run([
                'ffmpeg', '-y', '-hide_banner', '-loglevel', 'error',
                '-i', $joined, '-vn', '-ac', '1', '-ar', '16000', '-b:a', '48k',
                $disk->path($mp3Path),
            ]);

            if (! $ffmpeg->successful()) {
                throw new \RuntimeException('ffmpeg: '.mb_substr($ffmpeg->errorOutput(), 0, 300));
            }

            $seconds = (int) round((float) trim(Process::run([
                'ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'default=noprint_wrappers=1:nokey=1', $disk->path($mp3Path),
            ])->output()));

            $measured = [
                'audio_path' => $mp3Path,
                'duration_seconds' => $seconds ?: $recording->duration_seconds,
                'mime_type' => 'audio/mpeg',
            ];

            // An uploaded file's date is when it was saved — the end of the meeting.
            if ($recording->source === MeetingRecording::SOURCE_UPLOAD && $recording->ended_at && $seconds) {
                $measured['started_at'] = $recording->ended_at->copy()->subSeconds($seconds);
            }

            $recording->update($measured);

            // The MP3 is the copy kept; the pieces and the joined original go.
            @unlink($joined);
            $disk->deleteDirectory($recording->chunkDirectory());

            if (($seconds ?: (int) $recording->duration_seconds) < self::MIN_SECONDS) {
                $recording->update(['status' => MeetingRecording::STATUS_EMPTY, 'failure_reason' => 'too_short']);

                return;
            }

            $recording->update(['assemblyai_id' => $transcriber->submit($recording, $disk->path($mp3Path))]);

            CheckMeetingTranscript::dispatch($recording->id)->delay(now()->addSeconds(CheckMeetingTranscript::EVERY_SECONDS));
        } catch (\Throwable $e) {
            Log::channel('call_ai')->error('Meeting recording could not be prepared', ['meeting_recording_id' => $recording->id, 'error' => $e->getMessage()]);
            $recording->update(['status' => MeetingRecording::STATUS_FAILED, 'failure_reason' => mb_substr($e->getMessage(), 0, 500)]);
        }
    }
}
