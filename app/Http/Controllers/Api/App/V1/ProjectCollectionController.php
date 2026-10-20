<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Exceptions\Projects\DuplicateCollectionTitleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\CreateTimelapseFromSelectionRequest;
use App\Http\Requests\Api\App\MoveProjectFrameRequest;
use App\Http\Requests\Api\App\StoreProjectCollectionRequest;
use App\Http\Resources\Api\App\ProjectCollectionResource;
use App\Http\Resources\Api\App\ProjectPhotoResource;
use App\Models\Project;
use App\Models\ProjectTimelapse;
use App\Models\ProjectTimelapseFrame;
use App\Services\Projects\TimelapseCollectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The project Images page's Collections hub: list/create/delete a named
 * collection (timelapse or album), list a collection's frames for playback,
 * reorder/delete a frame, and build a new timelapse from a photo selection.
 * Every write goes through App\Services\Projects\TimelapseCollectionService
 * — the exact same code App\Livewire\Projects\TimelapseStudio calls — so
 * authorization and behavior are identical to the web's studio page.
 */
class ProjectCollectionController extends Controller
{
    public function index(Request $request, int $project, TimelapseCollectionService $service): JsonResponse
    {
        $found = $this->visibleProject($project);
        Gate::authorize('view', $found);

        // Every project has the catch-all album, created on demand — same
        // as TimelapseStudio::mount().
        ProjectTimelapse::generalFor($found);

        $canManageImages = (bool) $request->user()?->can('manageImages', $found);

        return response()->json([
            'data' => $service->collectionsFor($found)
                ->map(fn (ProjectTimelapse $collection) => (new ProjectCollectionResource($collection, $canManageImages))->toArray($request))
                ->values()
                ->all(),
        ]);
    }

    public function store(StoreProjectCollectionRequest $request, int $project, TimelapseCollectionService $service): JsonResponse
    {
        $found = $this->visibleProject($project);
        Gate::authorize('view', $found);

        try {
            $collection = $service->createCollection($found, $request->validated('title'));
        } catch (DuplicateCollectionTitleException) {
            throw ValidationException::withMessages([
                'title' => 'This project already has a collection with that name.',
            ]);
        }

        $collection->load(['frames' => fn ($q) => $q->orderBy('sort_order')->orderBy('id')]);

        return response()->json(['data' => (new ProjectCollectionResource($collection, true))->toArray($request)], 201);
    }

    public function destroy(Request $request, int $project, int $collection, TimelapseCollectionService $service): JsonResponse
    {
        $found = $this->visibleProject($project);

        $service->deleteCollection($found, $collection);

        return response()->json(['data' => ['id' => $collection, 'deleted' => true]]);
    }

    /** A collection's frames in play order — the playback/curation screen. */
    public function frames(Request $request, int $project, int $collection): JsonResponse
    {
        $found = $this->visibleProject($project);
        Gate::authorize('view', $found);

        $resolvedCollection = ProjectTimelapse::where('project_id', $found->id)->findOrFail($collection);

        $frames = ProjectTimelapseFrame::query()
            ->where('project_timelapse_id', $resolvedCollection->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => $frames->map(fn (ProjectTimelapseFrame $frame) => (new ProjectPhotoResource($frame))->toArray($request))->values()->all(),
        ]);
    }

    public function moveFrame(MoveProjectFrameRequest $request, int $project, int $frame, TimelapseCollectionService $service): JsonResponse
    {
        $found = $this->visibleProject($project);

        $service->moveFrame($found, $frame, (int) $request->validated('direction'));

        return response()->json(['data' => ['moved' => true]]);
    }

    public function destroyFrame(Request $request, int $project, int $frame, TimelapseCollectionService $service): JsonResponse
    {
        $found = $this->visibleProject($project);

        $service->deleteFrame($found, $frame);

        return response()->json(['data' => ['id' => $frame, 'deleted' => true]]);
    }

    public function createFromSelection(CreateTimelapseFromSelectionRequest $request, int $project, TimelapseCollectionService $service): JsonResponse
    {
        $found = $this->visibleProject($project);

        $result = $service->createTimelapseFromSelection($found, $request->validated('frame_ids'));

        if (! $result['ok']) {
            $message = $result['reason'] === 'too_few'
                ? 'Pick at least two photos to build a timelapse.'
                : 'Those photos could not be copied — nothing was created.';

            return response()->json(['message' => $message, 'reason' => $result['reason']], 422);
        }

        $collection = $result['timelapse'];
        $collection->load(['frames' => fn ($q) => $q->orderBy('sort_order')->orderBy('id')]);

        return response()->json([
            'data' => (new ProjectCollectionResource($collection, true))->toArray($request),
            'count' => $result['count'],
        ], 201);
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
