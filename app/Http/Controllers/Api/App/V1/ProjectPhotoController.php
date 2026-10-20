<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\StoreProjectPhotoRequest;
use App\Http\Resources\Api\App\ProjectPhotoResource;
use App\Livewire\Projects\TimelapseStudio;
use App\Models\Project;
use App\Models\ProjectTimelapse;
use App\Models\ProjectTimelapseFrame;
use App\Services\ProjectImageImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

/**
 * The project page's photo gallery: every frame across the project's
 * timelapse collections, and uploading a new one from the camera or the
 * library. Stored exactly the way the web's /projects/{project}/images
 * stores them — App\Services\ProjectImageImporter, the same archive +
 * sequence copies, the same deferred ProcessTimelapseFrame job — via
 * ProjectTimelapse::generalFor(), the catch-all album every project has
 * (the same default App\Http\Controllers\TimelapseController::store() uses
 * when the camera doesn't name a specific collection).
 *
 * `collection_id` targets a specific collection (the Collections hub's
 * capture flow — App\Services\Projects\TimelapseCollectionService::
 * collectionsFor() lists them), exactly like the web camera's own
 * `collection_id` field; a missing/foreign one falls back to the catch-all
 * album, never another project's collection.
 */
class ProjectPhotoController extends Controller
{
    private const PER_PAGE = 30;

    public function index(Request $request, int $project): JsonResponse
    {
        $found = $this->visibleProject($project);

        $page = ProjectTimelapseFrame::query()
            ->whereHas('timelapse', fn ($query) => $query->where('project_id', $found->id)
                ->when($request->filled('collection_id'), fn ($q) => $q->where('id', $request->integer('collection_id'))))
            ->orderByDesc('id')
            ->cursorPaginate(self::PER_PAGE);

        return response()->json([
            'data' => $page->getCollection()->map(fn (ProjectTimelapseFrame $frame) => (new ProjectPhotoResource($frame))->toArray($request))->all(),
            'next_cursor' => $page->nextCursor()?->encode(),
        ]);
    }

    public function store(StoreProjectPhotoRequest $request, int $project): JsonResponse
    {
        $found = $this->visibleProject($project);
        Gate::authorize('view', $found);

        $t0 = microtime(true);
        $upload = $request->file('photo');
        $sourcePath = $upload->getRealPath();
        $convertedPath = null;

        if (in_array(strtolower((string) $upload->getClientOriginalExtension()), ['heic', 'heif'], true)) {
            $convertedPath = ProjectImageImporter::convertHeicToJpeg($sourcePath);

            abort_if($convertedPath === null, 422, 'This HEIC could not be converted — try a JPG or PNG instead.');

            $sourcePath = $convertedPath;
        }

        $meta = TimelapseStudio::sanitizeCaptureMeta([
            'lat' => $request->input('lat'),
            'lng' => $request->input('lng'),
            'accuracy' => $request->input('accuracy'),
            'takenAt' => $request->input('taken_at'),
        ]);

        // The shot names its own collection, scoped to THIS project — a
        // forged/foreign id lands in the catch-all album, never someone
        // else's sequence (same rule as TimelapseController::store()).
        $target = $request->filled('collection_id')
            ? ProjectTimelapse::where('project_id', $found->id)->find($request->integer('collection_id'))
            : null;

        $frame = app(ProjectImageImporter::class)->storeImage(
            $target ?? ProjectTimelapse::generalFor($found),
            $sourcePath,
            $upload->getClientOriginalName(),
            null,
            ($meta['lat'] ?? null) !== null
                ? ['lat' => $meta['lat'], 'lng' => $meta['lng'], 'accuracy' => $meta['accuracy'] ?? null]
                : null,
            $meta['takenAt'] ?? null,
            auth()->id(),
            null,
            // The crew is standing there watching "Uploading…" — write the
            // bytes, answer, and let the queue do the pixel work.
            deferProcessing: true,
        );

        if ($convertedPath !== null) {
            @unlink($convertedPath);
        }

        Log::channel('timelapse')->info('Frame stored (Hive Contractors app upload)', [
            'frame_id' => $frame->id,
            'project_id' => $found->id,
            'bytes' => (int) $upload->getSize(),
            'store_ms' => (int) round((microtime(true) - $t0) * 1000),
        ]);

        return response()->json(['data' => (new ProjectPhotoResource($frame))->toArray($request)], 201);
    }

    /** A project the person may see: 404 when it doesn't exist, 403 when it isn't theirs. */
    private function visibleProject(int $id): Project
    {
        abort_unless(Project::withoutGlobalScopes()->whereKey($id)->exists(), 404);

        $project = Project::query()->whereKey($id)->first();
        abort_unless($project, 403, 'You do not have access to this project.');

        return $project;
    }
}
