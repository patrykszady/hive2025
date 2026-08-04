<?php

namespace App\Console\Commands;

use App\Jobs\AlignTimelapseFrame;
use App\Models\ProjectTimelapseFrame;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Re-run alignment + color matching for a project's whole timelapse against
 * the sequence's current median color profile — the anchor included. Run it
 * after shooting a batch, or whenever early frames (a light flare, say) look
 * off against the rest.
 */
class HarmonizeTimelapse extends Command
{
    protected $signature = 'timelapse:harmonize {project : Project id}';

    protected $description = 'Re-align and color-match every frame of a project timelapse to the sequence profile.';

    public function handle(): int
    {
        $frames = ProjectTimelapseFrame::query()
            ->whereHas('timelapse', fn ($q) => $q->where('project_id', (int) $this->argument('project')))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        if ($frames->isEmpty()) {
            $this->info('No frames.');

            return self::SUCCESS;
        }

        // The target must reflect the CURRENT sequence, not a cached one.
        Cache::forget("timelapse_target:{$frames->first()->project_timelapse_id}");

        foreach ($frames as $frame) {
            if ($frame->aligned_path) {
                Storage::disk($frame->disk)->delete($frame->aligned_path);
                $frame->forceFill(['aligned_path' => null])->save();
            }

            (new AlignTimelapseFrame($frame->id))->handle();

            $this->line(sprintf(
                '  frame %-4d %s',
                $frame->id,
                $frame->fresh()->aligned_path ? 'harmonized' : 'left original (low confidence)',
            ));
        }

        return self::SUCCESS;
    }
}
