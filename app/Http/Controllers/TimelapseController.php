<?php

namespace App\Http\Controllers;

use App\Models\ProjectTimelapseFrame;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

/**
 * Streams timelapse frames to the browser. Frames live on the private
 * 'files' disk (never symlinked into public/), so the browser reaches them
 * only through here — and only after proving it may view the project.
 */
class TimelapseController extends Controller
{
    public function frame(ProjectTimelapseFrame $frame)
    {
        // Resolve the project THROUGH ProjectScope — that scope IS the app's
        // row-level security (ProjectPolicy::view passes any vendor user), so
        // an out-of-scope project must 404 here exactly as /projects/{id}
        // does. Never reach for the frame's unscoped relation.
        $project = \App\Models\Project::query()
            ->whereKey($frame->timelapse?->project_id)
            ->firstOrFail();

        Gate::authorize('view', $project);

        $disk = Storage::disk($frame->disk);

        // The registered (aligned) copy when one exists — the sequence is
        // meant to be watched aligned. ?original=1 asks for the ARCHIVE copy:
        // the file the camera produced, full resolution, never re-encoded.
        // Frames shot before archive copies existed fall back to the sequence
        // copy, which is the most original thing they have.
        $path = request()->boolean('original')
            ? ($frame->original_path ?: $frame->path)
            : $frame->display_path;

        // A just-uploaded frame's sequence copy is written by a queued job
        // (ProcessTimelapseFrame) — until it lands, the archive copy stands
        // in so the frame is visible the moment the upload finishes.
        if (! $disk->exists($path) && $frame->original_path && $disk->exists($frame->original_path)) {
            $path = $frame->original_path;
        }

        abort_unless($disk->exists($path), 404);

        // Grids ask for ?thumb=1: a small copy built once and kept, rather
        // than the 1920px sequence frame behind every 120px tile.
        if (request()->boolean('thumb') && ! request()->boolean('download')) {
            $thumb = \App\Support\ImageThumbs::path(
                $frame->disk.':'.$path.':'.$disk->size($path),
                fn () => $disk->get($path),
            );

            if ($thumb) {
                return response()->file($thumb, \App\Support\ImageThumbs::headers());
            }
        }

        $headers = [
            'Content-Type' => 'image/jpeg',
            'Content-Length' => (string) $disk->size($path),
            // Frames never change once shot — let the browser keep them
            // (private: they still require auth to fetch).
            'Cache-Control' => 'private, max-age=604800, immutable',
        ];

        if (request()->boolean('download')) {
            $name = sprintf(
                'timelapse-%d-frame-%03d%s.jpg',
                $frame->timelapse->project_id,
                $frame->sort_order,
                request()->boolean('original') ? '-original' : '',
            );
            $headers['Content-Disposition'] = 'attachment; filename="'.$name.'"';
        }

        return response($disk->get($path), 200, $headers);
    }
}
