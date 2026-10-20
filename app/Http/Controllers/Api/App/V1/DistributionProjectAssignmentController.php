<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\AssignDistributionPercentsRequest;
use App\Models\Distribution;
use App\Models\Project;
use App\Services\Distributions\DistributionProjectAssignment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Assigning a project's profit split across distributions — mirrors the
 * web's App\Livewire\Distributions\{DistributionProjectsTable,
 * DistributionProjectsForm} (the "Projects With/Without Distributions"
 * tables on /distributions and their individual/bulk assign modals),
 * sharing App\Services\Distributions\DistributionProjectAssignment's
 * query/write logic with them so both write identically. Replaces the
 * round-1 "Open in Hive" hand-off on the app's Distributions list.
 */
class DistributionProjectAssignmentController extends Controller
{
    private const PER_PAGE = 10;

    /** The "Projects With/Without Distributions" table — mirrors DistributionProjectsTable::render(). */
    public function projects(Request $request, DistributionProjectAssignment $assignment): JsonResponse
    {
        $this->authorize('viewAny', Distribution::class);

        $type = $request->query('type') === 'With' ? 'With' : 'Without';
        $page = $assignment->projectsQuery($type)->paginate(self::PER_PAGE);

        return response()->json([
            'data' => $page->getCollection()->map(fn (Project $project) => $this->projectPayload($project))->values()->all(),
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'total' => $page->total(),
        ]);
    }

    /** Every distribution, unfilled — the bulk-assign modal's rows (DistributionProjectsForm::resetModal()). */
    public function options(DistributionProjectAssignment $assignment): JsonResponse
    {
        $this->authorize('viewAny', Distribution::class);

        return response()->json(['data' => $assignment->rows(null)]);
    }

    /** One project's prefilled rows — mirrors DistributionProjectsForm::addDis(). */
    public function show(int $project, DistributionProjectAssignment $assignment): JsonResponse
    {
        $this->authorize('viewAny', Distribution::class);

        $found = $this->visibleProject($project);
        $this->loadProjectRelations($found);

        return response()->json([
            'data' => [
                'project' => $this->projectPayload($found),
                'rows' => $assignment->rows($found),
            ],
        ]);
    }

    /** Syncs one project's split — mirrors DistributionProjectsForm::store(). */
    public function assign(AssignDistributionPercentsRequest $request, int $project, DistributionProjectAssignment $assignment): JsonResponse
    {
        $this->authorize('viewAny', Distribution::class);

        $found = $this->visibleProject($project);
        $assignment->assign($found, $request->percentsByDistributionId());

        $this->loadProjectRelations($found->refresh());

        return response()->json(['data' => $this->projectPayload($found)]);
    }

    /** Same "Complete" status filter projectsQuery() eager-loads, for a single project. */
    private function loadProjectRelations(Project $project): void
    {
        $project->load(['distributions', 'client.users', 'statuses' => function ($query) {
            $query->where('status_code', 7)->orderBy('start_date', 'asc');
        }]);
    }

    /** Assigns the same split to every completed project with none yet — mirrors DistributionProjectsForm::storeBulk(). */
    public function assignBulk(AssignDistributionPercentsRequest $request, DistributionProjectAssignment $assignment): JsonResponse
    {
        $this->authorize('viewAny', Distribution::class);

        $count = $assignment->assignBulk($request->percentsByDistributionId());

        return response()->json(['data' => ['assigned' => $count]]);
    }

    /**
     * @return array{id: int, name: string, address: ?string, client: ?string, profit: float, balance: float, total_project: float, completed_at: ?string, web_path: string}
     */
    private function projectPayload(Project $project): array
    {
        return [
            'id' => $project->id,
            'name' => $project->project_name,
            'address' => $project->short_address,
            'client' => $project->client?->name,
            'profit' => (float) data_get($project->finances ?? [], 'profit', 0),
            // The assign modal's two summary tiles — mirrors
            // projects-form.blade.php's own $projectProfit/$projectTotal
            // read off the same $project->finances array.
            'balance' => (float) data_get($project->finances ?? [], 'balance', 0),
            'total_project' => (float) data_get($project->finances ?? [], 'total_project', 0),
            'completed_at' => $project->statuses->first()?->start_date?->toDateString(),
            'web_path' => '/projects/'.$project->id,
        ];
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
