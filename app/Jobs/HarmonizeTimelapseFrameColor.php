<?php

namespace App\Jobs;

use App\Models\ProjectTimelapseFrame;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * Grade ONE frame's colors toward the sequence anchor — a whole-image LAB
 * adjustment (scripts/harmonize_frames.py), never regional: no masks, no
 * sky handling, nothing that can wash over a tree line.
 *
 * The graded result lands in aligned_path — the copy viewers prefer — so
 * the sequence copy and the archive original stay untouched and the whole
 * thing is re-derivable. A frame with no aligned copy gets one (identity
 * geometry, graded colors), the same shape the photo-only anchor already
 * has.
 */
class HarmonizeTimelapseFrameColor implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $backoff = 30;

    public int $timeout = 55;

    public function __construct(public int $frameId, public int $anchorFrameId)
    {
    }

    public function handle(): void
    {
        $frame = ProjectTimelapseFrame::find($this->frameId);
        $anchor = ProjectTimelapseFrame::find($this->anchorFrameId);

        if (! $frame || ! $anchor || $frame->id === $anchor->id) {
            return;
        }

        $python = (string) config('services.timelapse_align.python');

        if (! is_executable($python)) {
            return;
        }

        $disk = Storage::disk($frame->disk);
        $anchorPath = Storage::disk($anchor->disk)->path($anchor->display_path);
        $sourcePath = $disk->path($frame->display_path);

        if (! is_file($anchorPath) || ! is_file($sourcePath)) {
            return;
        }

        $alignedRelative = $frame->aligned_path
            ?: preg_replace('/([^\/]+)$/', 'aligned-$1', $frame->path);
        $outAbsolute = $disk->path($alignedRelative).'.grading.jpg';

        $process = new Process([
            $python,
            base_path('scripts/harmonize_frames.py'),
            $anchorPath,
            $sourcePath,
            $outAbsolute,
        ]);
        $process->setTimeout(50);
        $process->run();

        $out = json_decode(trim($process->getOutput()), true) ?: [];

        if ($process->getExitCode() !== 0 || ! ($out['ok'] ?? false) || ! is_file($outAbsolute)) {
            @unlink($outAbsolute);
            Log::channel('timelapse')->warning('Harmonize frame failed', [
                'frame_id' => $frame->id,
                'exit' => $process->getExitCode(),
                'stderr' => mb_substr($process->getErrorOutput(), 0, 500),
            ] + $out);

            return;
        }

        rename($outAbsolute, $disk->path($alignedRelative));
        $frame->forceFill(['aligned_path' => $alignedRelative])->save();
        // updated_at is the cache-buster behind the frame's immutable URLs,
        // and save() skips it when aligned_path didn't change.
        $frame->touch();

        Log::channel('timelapse')->info('Frame harmonized', [
            'frame_id' => $frame->id,
            'anchor_frame_id' => $anchor->id,
        ] + $out);
    }
}
