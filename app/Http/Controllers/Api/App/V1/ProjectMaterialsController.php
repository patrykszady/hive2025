<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\Projects\AppProjectMaterialsReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The project page's Materials card, read-only — see
 * App\Services\Projects\AppProjectMaterialsReader. The OCR receipt
 * upload/vendor-match flow App\Livewire\Projects\ProjectMaterials also
 * drives stays web-only. Admin-only, same as the web's viewMaterials gate.
 */
class ProjectMaterialsController extends Controller
{
    public function index(Request $request, int $project, AppProjectMaterialsReader $reader): JsonResponse
    {
        $found = $this->visibleProject($project);
        Gate::authorize('viewMaterials', $found);

        return response()->json(['data' => $reader->listFor($found)]);
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
