<?php

namespace App\Jobs;

use App\Models\CallLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class StoreCallRecording implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 15;

    /**
     * Create a new job instance.
     */
    public function __construct(public int $callLogId) {}

    /**
     * Download the remote Telnyx recording and store it locally.
     *
     * Telnyx S3 signed URLs expire after ~10 minutes, so this must
     * run promptly after the call.recording.saved webhook.
     */
    public function handle(): void
    {
        $callLog = CallLog::find($this->callLogId);

        if (! $callLog || ! $callLog->recording_url) {
            return;
        }

        // Already stored locally — nothing to do
        if (str_starts_with($callLog->recording_url, '/storage/')) {
            return;
        }

        try {
            $response = Http::timeout(30)->get($callLog->recording_url);

            if ($response->failed()) {
                Log::channel('telnyx')->error('Failed to download call recording', [
                    'call_log_id' => $this->callLogId,
                    'status' => $response->status(),
                ]);

                return;
            }

            $contentType = $response->header('Content-Type');
            $extension = match (true) {
                str_contains($contentType, 'mp3'), str_contains($contentType, 'mpeg') => 'mp3',
                str_contains($contentType, 'wav') => 'wav',
                default => 'mp3',
            };

            $filename = 'call-recordings/' . now()->format('Y/m') . '/' . Str::uuid() . '.' . $extension;

            Storage::disk('local')->put('public/' . $filename, $response->body());

            $callLog->update([
                'recording_url' => '/storage/' . $filename,
                'recording_disk' => 'local',
                'recording_path' => 'public/' . $filename,
                'purge_after' => $callLog->purge_after
                    ?? now()->addDays((int) config('call_recording.retention_days', 180)),
            ]);

            Log::channel('telnyx')->info('Call recording stored locally', [
                'call_log_id' => $this->callLogId,
                'path' => $filename,
            ]);

            // Transcription + summarization run via the `calls:process-recordings` artisan command
            // (scheduled / manual), not auto-dispatched here.
        } catch (\Exception $e) {
            Log::channel('telnyx')->error('Exception downloading call recording', [
                'call_log_id' => $this->callLogId,
                'error' => $e->getMessage(),
            ]);

            throw $e; // Re-throw so the queue retries
        }
    }
}
