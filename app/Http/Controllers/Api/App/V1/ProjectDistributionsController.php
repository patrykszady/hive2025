<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Models\Distribution;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The project page's Distributions card, exactly as
 * App\Livewire\Projects\ProjectDistributions shows it
 * ($project->load('distributions')). Admin-only, nested under the web's
 * viewFinancials + update gates (the same column a plain Member never
 * sees), so this checks both.
 */
class ProjectDistributionsController extends Controller
{
    public function index(Request $request, int $project): JsonResponse
    {
        $found = $this->visibleProject($project);
        Gate::authorize('viewFinancials', $found);
        Gate::authorize('update', $found);

        return response()->json([
            'data' => $found->distributions->map(fn (Distribution $distribution) => [
                'id' => $distribution->id,
                'name' => $distribution->name,
                'percent' => (float) $distribution->pivot->percent,
                'amount' => (float) $distribution->pivot->amount,
                'created_at' => $distribution->pivot->created_at?->toIso8601String(),
            ])->values()->all(),
        ]);
    }

    /** A project the person may see: 404 when it doesn't exist, 403 when it isn't theirs. */
    private function visibleProject(int $id): Project
    {
        abort_unless(Project::withoutGlobalScopes()->whereKey($id)->exists(), 404);

        $project = Project::query()->whereKey($id)->first();
        abort_unless($project, 403, 'You do not have access to this project.');

        return $project->load('distributions');
    }
}
