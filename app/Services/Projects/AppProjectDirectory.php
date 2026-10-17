<?php

namespace App\Services\Projects;

use App\Livewire\Projects\ProjectsIndex;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The app Projects tab's query building, matching the web index
 * (ProjectsIndex/ProjectsTable) card order, default filter and search.
 *
 * Status filtering is left to Project::scopeStatus() (a whereHas against the
 * latestStatus relation), which already resolves "latest" per the
 * authenticated vendor via ProjectStatusScope — no vendor id needs passing
 * in here. Ordering is different: a cursor paginator rebuilds its WHERE
 * clauses from the ORDER BY columns on every page, and a correlated subquery
 * with its own bound parameter in an ORDER BY does not survive that rebuild
 * (the cursor's comparison value and the subquery's own binding collide —
 * confirmed empirically against the dev database before writing this). A
 * LEFT JOIN to a one-row-per-project derived table sidesteps it: the ordered
 * column is then a plain joined column, not a parameterized expression.
 */
class AppProjectDirectory
{
    /**
     * The statuses that get a filter chip, in the web's card order
     * (ProjectsIndex::STATUS_CARD_CODES).
     *
     * @return list<array{code: int, title: string, color: string}>
     */
    public function statuses(): array
    {
        $statuses = collect(ProjectStatus::selectableStatuses())->keyBy('code');

        return collect(ProjectsIndex::STATUS_CARD_CODES)
            ->map(fn (int $code): array => [
                'code' => $code,
                'title' => $statuses[$code]['label'],
                'color' => $statuses[$code]['color'],
            ])
            ->values()
            ->all();
    }

    /**
     * Projects visible to $user (via ProjectScope, applied automatically),
     * filtered to $statusCodes (empty = every status) and $search (name,
     * address, or client — Meilisearch first, a plain LIKE if that index
     * call fails), newest-status-first like the web's default sort.
     *
     * @param  list<int>  $statusCodes
     */
    public function query(User $user, array $statusCodes, string $search): Builder
    {
        $vendorId = (int) $user->vendor->id;

        $latestStatusSub = ProjectStatus::query()->withoutGlobalScopes()
            ->select(['project_id', 'start_date'])
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY project_id ORDER BY start_date DESC, id DESC) as rn')
            ->where('belongs_to_vendor_id', $vendorId);

        $query = Project::query()
            ->leftJoinSub($latestStatusSub, 'vendor_status', function ($join) {
                $join->on('vendor_status.project_id', '=', 'projects.id')
                    ->where('vendor_status.rn', 1);
            })
            ->select([
                'projects.*',
                'vendor_status.start_date as latest_vendor_status_date',
            ])
            ->with(['client']);

        if ($statusCodes !== []) {
            $query->status($statusCodes);
        }

        $this->applySearch($query, trim($search));

        return $query
            ->orderByDesc('latest_vendor_status_date')
            ->orderByDesc('projects.id');
    }

    /**
     * Sets a transient `upcoming_task_row` attribute (read by ProjectResource)
     * on each project, from one batched query across the whole page rather
     * than one query per row.
     *
     * @param  Collection<int, Project>  $projects
     */
    public function attachUpcomingTaskSummaries(Collection $projects): void
    {
        $projectIds = $projects->pluck('id')->all();

        if ($projectIds === []) {
            return;
        }

        $tasks = Task::query()
            ->whereIn('project_id', $projectIds)
            ->whereNotNull('start_date')
            ->whereNotNull('end_date')
            ->where('end_date', '>=', today())
            ->orderBy('start_date')
            ->orderBy('id')
            ->get(['id', 'project_id', 'title', 'start_date']);

        $byProject = $tasks->groupBy('project_id');

        foreach ($projects as $project) {
            $next = $byProject->get($project->id)?->first();

            $project->upcoming_task_row = $next ? [
                'id' => $next->id,
                'title' => $next->title,
                'date' => $next->start_date->format('Y-m-d'),
            ] : null;
        }
    }

    private function applySearch(Builder $query, string $search): void
    {
        if ($search === '') {
            return;
        }

        try {
            $ids = Project::search($search)->take(200)->keys()->map(fn ($id) => (int) $id)->all();
            $query->whereIn('projects.id', $ids);

            return;
        } catch (\Throwable $exception) {
            report($exception);
        }

        // Meilisearch unavailable: a plain LIKE across the same fields the
        // web's search covers (project name, address, client).
        $like = '%'.$search.'%';
        $query->where(function (Builder $inner) use ($like) {
            $inner->where('project_name', 'like', $like)
                ->orWhere('address', 'like', $like)
                ->orWhereHas('client', function (Builder $client) use ($like) {
                    $client->where('business_name', 'like', $like)
                        ->orWhereHas('users', function (Builder $clientUser) use ($like) {
                            $clientUser->where('first_name', 'like', $like)
                                ->orWhere('last_name', 'like', $like);
                        });
                });
        });
    }
}
