<?php

namespace App\Jobs;

use App\Models\ProjectTimelapseFrame;
use App\Services\ProjectImageImporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManagerStatic as Image;

/**
 * Derive a frame's sequence copy (oriented, capped to MAX_EDGE) from its
 * archive copy, off the request path.
 *
 * The upload request used to decode/re-encode the multi-MB camera JPEG
 * inline, which is most of why saving a frame dragged — now it only writes
 * the archive bytes and a row, and this job does the pixel work. Until it
 * runs, the frame route serves the archive copy, so the frame is visible the
 * moment the upload lands.
 */
class ProcessTimelapseFrame implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 15;

    /** Horizon runs 60s workers — stay inside that, don't get SIGKILLed. */
    public int $timeout = 55;

    public function __construct(public int $frameId)
    {
    }

    public function handle(): void
    {
        $t0 = microtime(true);
        $frame = ProjectTimelapseFrame::find($this->frameId);

        if (! $frame) {
            return;
        }

        $disk = Storage::disk($frame->disk);

        if (! $disk->exists($frame->path)) {
            if (! $frame->original_path || ! $disk->exists($frame->original_path)) {
                Log::channel('timelapse')->warning('Frame processing skipped — no archive copy', [
                    'frame_id' => $frame->id,
                ]);

                return;
            }

            $image = Image::make($disk->path($frame->original_path))->orientate();

            if (max($image->width(), $image->height()) > ProjectImageImporter::MAX_EDGE) {
                $image->resize(ProjectImageImporter::MAX_EDGE, ProjectImageImporter::MAX_EDGE, function ($constraint) {
                    $constraint->aspectRatio();
                    $constraint->upsize();
                });
            }

            $disk->put($frame->path, (string) $image->encode('jpg', 88));
            $image->destroy();
        }

        Log::channel('timelapse')->info('Frame processed', [
            'frame_id' => $frame->id,
            'ms' => (int) round((microtime(true) - $t0) * 1000),
        ]);

        // Alignment needs the sequence copy this job just wrote — that's why
        // it's chained from here rather than dispatched alongside.
        if ($frame->timelapse?->isTimelapse()) {
            AlignTimelapseFrame::dispatch($frame->id);
        }
    }
}
