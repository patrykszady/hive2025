<?php

namespace App\Http\Controllers;

use App\Models\ProjectTimelapse;
use App\Models\ProjectTimelapseFrame;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Streams timelapse frames to the browser, and accepts the camera's direct
 * frame uploads. Frames live on the private 'files' disk (never symlinked
 * into public/), so the browser reaches them only through here — and only
 * after proving it may view the project.
 */
class TimelapseController extends Controller
{
    /**
     * A captured frame, POSTed straight from the camera in ONE request.
     *
     * This exists because Livewire's file-upload handshake (signed-url
     * commit, then the POST, then a finish commit) stalled silently on
     * iPhones — progress 0, no error callback, nothing in any log. A plain
     * authed endpoint has a status code or it has nothing, and either way
     * the client's retry queue knows what happened.
     */
    public function store(Request $request, \App\Models\Project $project)
    {
        Gate::authorize('view', $project);

        $request->validate([
            'frame' => 'required|file|mimes:jpg,jpeg,png|max:20480',
            'collection_id' => 'nullable|integer',
            'taken_at' => 'nullable|string|max:64',
            'lat' => 'nullable|numeric',
            'lng' => 'nullable|numeric',
            'accuracy' => 'nullable|numeric',
        ]);

        $t0 = microtime(true);

        // The shot names its own collection; the lookup is scoped to THIS
        // project, so a forged id lands in the catch-all album, never in
        // someone else's sequence.
        $target = $request->filled('collection_id')
            ? ProjectTimelapse::where('project_id', $project->id)->find($request->integer('collection_id'))
            : null;
        $timelapse = $target ?? ProjectTimelapse::generalFor($project);

        $meta = \App\Livewire\Projects\TimelapseStudio::sanitizeCaptureMeta([
            'lat' => $request->input('lat'),
            'lng' => $request->input('lng'),
            'accuracy' => $request->input('accuracy'),
            'takenAt' => $request->input('taken_at'),
        ]);

        $frame = app(\App\Services\ProjectImageImporter::class)->storeImage(
            $timelapse,
            $request->file('frame')->getRealPath(),
            null,
            null,
            ($meta['lat'] ?? null) !== null
                ? ['lat' => $meta['lat'], 'lng' => $meta['lng'], 'accuracy' => $meta['accuracy'] ?? null]
                : null,
            $meta['takenAt'] ?? null,
            auth()->id(),
            null,
            deferProcessing: true,
        );

        Log::channel('timelapse')->info('Frame stored (direct upload)', [
            'frame_id' => $frame->id,
            'project_id' => $project->id,
            'collection_id' => $timelapse->id,
            'bytes' => (int) $request->file('frame')->getSize(),
            'store_ms' => (int) round((microtime(true) - $t0) * 1000),
        ]);

        return response()->json(['frame_id' => $frame->id], 201);
    }

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
        // meant to be watched aligned. ?raw=1 asks for the UN-ALIGNED
        // sequence copy — the studio's manual aligner edits that image, and
        // showing the already-warped one would compound the transform on
        // save. Both are FACE-BLURRED display copies.
        //
        // The archive original is deliberately NOT reachable from here: it
        // is unblurred and carries EXIF, so it lives at its own unguessable
        // address behind a narrower gate — see original() below. A stale
        // ?original=1 link now simply serves the display copy.
        $path = request()->boolean('raw') ? $frame->path : $frame->display_path;

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
                'timelapse-%d-frame-%03d.jpg',
                $frame->timelapse->project_id,
                $frame->sort_order,
            );
            $headers['Content-Disposition'] = 'attachment; filename="'.$name.'"';
        }

        return response($disk->get($path), 200, $headers);
    }

    /**
     * The ARCHIVE original: the file the camera produced — full resolution,
     * never re-encoded, EXIF intact, and NOT face-blurred.
     *
     * Addressed by a 48-char random token rather than the frame's id, so the
     * set of archives is not enumerable by counting, and gated to the person
     * who took the photo plus Admins of the vendor that owns the project.
     * Everyone else who can see the project still gets the blurred display
     * copy through frame() — they simply never see this link.
     */
    public function original(string $token)
    {
        $frame = ProjectTimelapseFrame::where('archive_token', $token)->firstOrFail();

        // Same row-level security as frame(): resolve THROUGH ProjectScope so
        // an out-of-scope project 404s rather than leaking existence.
        $project = \App\Models\Project::query()
            ->whereKey($frame->timelapse?->project_id)
            ->firstOrFail();

        Gate::authorize('view', $project);

        // 404, not 403: a wrong-hands token should not confirm that it names
        // a real file.
        abort_unless($frame->archiveVisibleTo(auth()->user()), 404);

        $disk = Storage::disk($frame->disk);
        // Frames shot before archive copies existed fall back to the sequence
        // copy — the most original thing they have.
        $path = $frame->original_path && $disk->exists($frame->original_path)
            ? $frame->original_path
            : $frame->path;

        abort_unless($disk->exists($path), 404);

        $headers = [
            'Content-Type' => 'image/jpeg',
            'Content-Length' => (string) $disk->size($path),
            // Private and never revalidated by shared caches: this body is
            // the unblurred original.
            'Cache-Control' => 'private, no-store',
        ];

        if (request()->boolean('download')) {
            $headers['Content-Disposition'] = 'attachment; filename="'.sprintf(
                'timelapse-%d-frame-%03d-original.jpg',
                $frame->timelapse->project_id,
                $frame->sort_order,
            ).'"';
        }

        return response($disk->get($path), 200, $headers);
    }
}
