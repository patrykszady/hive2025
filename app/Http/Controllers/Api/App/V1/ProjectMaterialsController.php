<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\StoreProjectMaterialRequest;
use App\Models\Project;
use App\Services\Projects\AppProjectMaterialsReader;
use App\Services\Projects\ProjectMaterialsUploader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The project page's Materials card — see
 * App\Services\Projects\AppProjectMaterialsReader for the read side and
 * App\Services\Projects\ProjectMaterialsUploader for the OCR
 * upload/vendor-match flow, both shared with
 * App\Livewire\Projects\ProjectMaterials. Admin-only, same as the web's
 * viewMaterials gate. The app only offers a photo (no PDF picker is wired
 * into the Expo build), but the server accepts the same file types as the
 * web either way.
 */
class ProjectMaterialsController extends Controller
{
    public function index(Request $request, int $project, AppProjectMaterialsReader $reader): JsonResponse
    {
        $found = $this->visibleProject($project);
        Gate::authorize('viewMaterials', $found);

        return response()->json(['data' => $reader->listFor($found)]);
    }

    public function store(StoreProjectMaterialRequest $request, int $project, ProjectMaterialsUploader $uploader, AppProjectMaterialsReader $reader): JsonResponse
    {
        $found = $this->visibleProject($project);
        Gate::authorize('viewMaterials', $found);

        $user = $request->user();

        try {
            $expense = $uploader->upload(
                $found,
                $request->file('file'),
                $request->validated('belongs_to_vendor_id') ? (int) $request->validated('belongs_to_vendor_id') : null,
                (int) $user->id,
                (int) $user->vendor->id,
            );
        } catch (ValidationException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'errors' => $exception->errors()], 422);
        }

        return response()->json(['data' => $reader->one($expense)], 201);
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
