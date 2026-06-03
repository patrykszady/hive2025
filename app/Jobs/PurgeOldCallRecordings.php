<?php

namespace App\Jobs;

use App\Models\CallLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class PurgeOldCallRecordings implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function handle(): void
    {
        $count = 0;
        CallLog::whereNotNull('purge_after')
            ->where('purge_after', '<=', now())
            ->where(function ($q) {
                $q->whereNotNull('recording_path')
                    ->orWhereNotNull('recording_url')
                    ->orWhereHas('transcript');
            })
            ->chunkById(100, function ($logs) use (&$count) {
                foreach ($logs as $log) {
                    if ($log->recording_path && $log->recording_disk) {
                        try {
                            Storage::disk($log->recording_disk)->delete($log->recording_path);
                        } catch (\Throwable $e) {
                            Log::warning('PurgeOldCallRecordings: file delete failed', [
                                'call_log_id' => $log->id,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }

                    $log->transcript()?->delete();

                    $log->update([
                        'recording_url' => null,
                        'recording_disk' => null,
                        'recording_path' => null,
                        'recording_telnyx_id' => null,
                        'purge_after' => null,
                    ]);

                    $count++;
                }
            });

        Log::info('PurgeOldCallRecordings: completed', ['purged' => $count]);
    }
}
