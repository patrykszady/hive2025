<?php

namespace App\Http\Resources\Api\App;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * A client as the app's Clients tab shows it. With withDetail() it adds
 * everything the detail screen needs beyond the list row — see
 * ClientController::show().
 *
 * @mixin \App\Models\Client
 */
class ClientResource extends JsonResource
{
    protected bool $detail = false;

    public function withDetail(): static
    {
        $this->detail = true;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $row = [
            'id' => $this->id,
            'name' => $this->name,
            'address' => [
                'line1' => $this->address,
                'line2' => $this->address_2,
                'city' => $this->city,
                'state' => $this->state,
                'zip' => $this->zip_code,
            ],
            'home_phone' => $this->home_phone,
            'people' => $this->resource->users->map(fn (User $user) => [
                'id' => $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'cell_phone' => $user->cell_phone,
                ...($this->detail ? ['email' => $user->email] : []),
            ])->values()->all(),
            'projects_count' => $this->resource->projects_count ?? $this->resource->projects()->count(),
            'created_at' => $this->created_at?->toIso8601String(),
            'web_path' => '/clients/'.$this->id,
        ];

        if (! $this->detail) {
            return $row;
        }

        $actingUser = $request->user();

        return $row + [
            // Edit-screen fields — App\Livewire\Forms\ClientForm::update().
            // Note: unlike the web's edit form (which leaves Business Name
            // blank until retyped — ClientForm::setClient() never prefills
            // it, so an unmodified web edit actually clears the name), the
            // app prefills this from the current value so saving without
            // changes never wipes it.
            'business_name' => $this->business_name,
            'source' => $this->resource->source,
            'can_edit' => $actingUser->can('update', $this->resource),
            'can_delete' => $actingUser->can('delete', $this->resource),
            'can_add_person' => $actingUser->can('create_client_member', [User::class, $this->resource]),
            // Newest status first, same as the web's Client Projects table
            // (App\Livewire\Projects\ProjectsTable::projects()'s
            // Project::scopedSearch(..., 'latest_status_date', 'desc')); a
            // project with no status at all sorts last.
            'projects' => $this->resource->projects
                ->sortByDesc(fn (Project $project) => $project->latestStatus?->start_date ?? Carbon::createFromTimestamp(0))
                ->values()
                ->map(fn (Project $project) => [
                    'id' => $project->id,
                    'name' => $project->project_name,
                    'address' => [
                        'line1' => $project->address,
                        'line2' => $project->address_2,
                        'city' => $project->city,
                        'state' => $project->state,
                        'zip' => $project->zip_code,
                        'short' => $project->short_address,
                    ],
                    'status' => $this->projectStatus($project),
                    'latest_status_date' => $project->latestStatus?->start_date?->toDateString(),
                ])->values()->all(),
            'upcoming_tasks' => $this->upcomingTasks(),
            // Hive has no client-notes table (checked the schema — there is
            // no `notes` or `client_notes` table at all), so this is always
            // empty rather than fabricated; the shape is here so the app
            // doesn't need a special case if one is added later.
            'notes' => [],
        ];
    }

    /**
     * @return array{code: int, title: string, color: string}|null
     */
    private function projectStatus(Project $project): ?array
    {
        $status = $project->latestStatus;

        if (! $status) {
            return null;
        }

        return [
            'code' => $status->status_code,
            'title' => $status->title,
            'color' => $status->badge_color,
        ];
    }

    /**
     * The next 10 tasks across every project this client has, the same
     * window UpcomingClientTasks uses.
     *
     * @return list<array{id: int, title: string, project_id: int, date: string}>
     */
    private function upcomingTasks(): array
    {
        $projectIds = $this->resource->projects->pluck('id');

        if ($projectIds->isEmpty()) {
            return [];
        }

        return Task::query()
            ->whereIn('project_id', $projectIds)
            ->whereNotNull('start_date')
            ->whereNotNull('end_date')
            ->where('end_date', '>=', today())
            ->orderBy('start_date')
            ->orderBy('id')
            ->limit(10)
            ->get(['id', 'project_id', 'title', 'start_date'])
            ->map(fn (Task $task) => [
                'id' => $task->id,
                'title' => $task->title,
                'project_id' => $task->project_id,
                'date' => $task->start_date->format('Y-m-d'),
            ])
            ->values()
            ->all();
    }
}
