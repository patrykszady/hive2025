<?php

namespace App\Console\Commands;

use App\Jobs\AlignTimelapseFrame;
use App\Models\ProjectTimelapse;
use App\Models\ProjectTimelapseFrame;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

/**
 * Re-run alignment + colour grading over timelapses that already exist.
 *
 * Frames are only ever aligned when they are UPLOADED, so shipping an
 * improved pipeline does nothing to the history already in the database —
 * this is the command that applies it retroactively.
 *
 * Two things it is careful about:
 *
 *  - ORDER. Every frame races a fit against the anchor and a fit against its
 *    nearest already-aligned neighbour, so frames are processed OUTWARD FROM
 *    THE ANCHOR and chained (never fanned out in parallel): by the time a
 *    frame runs, the neighbour it wants to match already exists. Fanning out
 *    would leave each frame matching the distant anchor cold, which is what
 *    made early frames drift.
 *
 *  - THE ANCHOR'S COMPOSITION. When a human re-anchored a sequence in the
 *    studio, the anchor's aligned copy IS the canvas everything else was
 *    registered onto. It is never cleared here; only frames that follow it
 *    are re-derived.
 */
class ReprocessTimelapses extends Command
{
    protected $signature = 'timelapse:reprocess
        {--project= : only timelapses on this project id}
        {--timelapse= : only this collection id}
        {--sync : process inline now instead of queueing (needs a long-running shell)}
        {--dry-run : list what would happen and change nothing}';

    protected $description = 'Re-align and re-grade existing timelapse frames with the current pipeline';

    public function handle(): int
    {
        $collections = ProjectTimelapse::query()
            ->where('kind', ProjectTimelapse::KIND_TIMELAPSE)
            ->when($this->option('project'), fn ($q, $id) => $q->where('project_id', $id))
            ->when($this->option('timelapse'), fn ($q, $id) => $q->whereKey($id))
            ->orderBy('project_id')
            ->get();

        if ($collections->isEmpty()) {
            $this->warn('No timelapses matched.');

            return self::SUCCESS;
        }

        $dry = (bool) $this->option('dry-run');
        $totalFrames = 0;

        foreach ($collections as $collection) {
            $frames = $collection->frames()->orderBy('sort_order')->orderBy('id')->get();

            if ($frames->count() < 2) {
                $this->line("timelapse #{$collection->id} (project {$collection->project_id}): skipped — needs 2+ frames");

                continue;
            }

            // Resolved by the model, never re-derived here: anchorFrame()
            // already falls back to the earliest frame when no anchor was
            // chosen, and duplicating that rule risks the backfill quietly
            // registering a sequence onto a different frame than the studio
            // and the viewer use.
            $anchor = $collection->anchorFrame();

            if (! $anchor) {
                $this->line("timelapse #{$collection->id}: skipped — no anchor could be resolved");

                continue;
            }

            if ($collection->anchor_frame_id !== $anchor->id && ! $dry) {
                $collection->forceFill(['anchor_frame_id' => $anchor->id])->save();
            }

            // Nearest-to-the-anchor first, spreading outward.
            $queue = $frames
                ->reject(fn (ProjectTimelapseFrame $f) => $f->id === $anchor->id)
                ->sortBy(fn (ProjectTimelapseFrame $f) => abs($f->sort_order - $anchor->sort_order))
                ->values();

            $this->line(sprintf(
                'timelapse #%d (project %d): anchor #%d, %d frame(s) to re-derive%s',
                $collection->id,
                $collection->project_id,
                $anchor->id,
                $queue->count(),
                $dry ? ' [dry run]' : '',
            ));

            if ($dry) {
                $totalFrames += $queue->count();

                continue;
            }

            // AlignTimelapseFrame returns early when a frame already has an
            // aligned copy, so the derived state must go first.
            foreach ($queue as $frame) {
                if ($frame->aligned_path) {
                    Storage::disk($frame->disk)->delete($frame->aligned_path);
                }

                $frame->forceFill(['aligned_path' => null, 'align_transform' => null])->save();
            }

            $jobs = [];

            // The anchor only needs a pass of its own if it has no display
            // copy yet — that pass is photo-only (colour, no geometry).
            if (! $anchor->aligned_path) {
                $jobs[] = new AlignTimelapseFrame($anchor->id, reframe: true);
            }

            foreach ($queue as $frame) {
                $jobs[] = new AlignTimelapseFrame($frame->id, reframe: true);
            }

            if ($this->option('sync')) {
                foreach ($jobs as $job) {
                    dispatch_sync($job);
                    $this->getOutput()->write('.');
                }
                $this->newLine();
            } else {
                Bus::chain($jobs)->dispatch();
            }

            $totalFrames += $queue->count();
        }

        $this->newLine();
        $this->info(sprintf(
            '%s %d frame(s) across %d timelapse(s).%s',
            $dry ? 'Would re-derive' : ($this->option('sync') ? 'Re-derived' : 'Queued'),
            $totalFrames,
            $collections->count(),
            $dry || $this->option('sync') ? '' : ' Watch Horizon; each frame takes a few seconds.',
        ));

        return self::SUCCESS;
    }
}
