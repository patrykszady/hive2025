<?php

namespace App\Jobs;

use App\Models\ProjectTimelapse;
use App\Models\ProjectTimelapseFrame;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

/**
 * Chain a whole-image color grade of every frame toward the sequence's
 * ALIGNMENT ANCHOR — the same frame the geometry registers onto — so
 * scrubbing the timelapse holds one consistent look. The anchor itself is
 * the reference and stays untouched.
 *
 * A chain rather than one loop: Horizon runs 60s workers, and a long
 * sequence graded in one job would be SIGKILLed halfway.
 */
class HarmonizeTimelapseColors implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $backoff = 30;

    public int $timeout = 55;

    public function __construct(public int $timelapseId)
    {
    }

    public function handle(): void
    {
        $timelapse = ProjectTimelapse::find($this->timelapseId);

        if (! $timelapse || $timelapse->kind !== ProjectTimelapse::KIND_TIMELAPSE) {
            return;
        }

        $anchor = $timelapse->anchorFrame();

        if (! $anchor) {
            return;
        }

        $frames = ProjectTimelapseFrame::query()
            ->where('project_timelapse_id', $timelapse->id)
            ->where('id', '!=', $anchor->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        if ($frames->isEmpty()) {
            return;
        }

        Log::channel('timelapse')->info('Harmonizing toward anchor', [
            'timelapse_id' => $timelapse->id,
            'anchor_frame_id' => $anchor->id,
            'frames' => $frames->count(),
        ]);

        Bus::chain(
            $frames->map(fn (ProjectTimelapseFrame $f) => new HarmonizeTimelapseFrameColor($f->id, $anchor->id))->all()
        )->dispatch();
    }
}
