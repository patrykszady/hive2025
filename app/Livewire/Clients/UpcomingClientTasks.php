<?php

namespace App\Livewire\Clients;

use App\Livewire\Concerns\HasLaterTasks;
use App\Models\Client;
use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

class UpcomingClientTasks extends Component
{
    use HasLaterTasks;
    protected $listeners = ['refreshComponent' => '$refresh'];

    public Client $client;

    /**
     * Get today's date in the app timezone.
     */
    protected function getToday(): Carbon
    {
        return Carbon::today((string) config('app.timezone'));
    }

    /**
     * Get project IDs for this client.
     */
    protected function getProjectIds(): Collection
    {
        return $this->client->projects()->pluck('id');
    }

    /**
     * Get today's date string (Y-m-d) for the view.
     * Uses browser timezone so "Today" badge reflects the user's local time.
     */
    #[Computed]
    public function todayDate(): string
    {
        return browser_today()->format('Y-m-d');
    }

    /**
     * Get tomorrow's date string (Y-m-d) for the view.
     * Uses browser timezone so "Tomorrow" badge reflects the user's local time.
     */
    #[Computed]
    public function tomorrowDate(): string
    {
        return browser_today()->addDay()->format('Y-m-d');
    }

    /**
     * Get tasks grouped by date across all client projects.
     *
     * @return Collection<string, Collection<int, Task>>
     */
    #[Computed]
    public function groupedTasks(): Collection
    {
        $today = browser_today();
        $cutoff = $today->copy()->subDays(7);
        $windowEnd = $today->copy()->addDays(6);

        $cutoffStr = $cutoff->format('Y-m-d');
        $windowEndStr = $windowEnd->format('Y-m-d');

        $projectIds = $this->getProjectIds();

        if ($projectIds->isEmpty()) {
            return collect();
        }

        $tasks = Task::withTrashed()
            ->whereIn('project_id', $projectIds)
            ->whereNotNull('start_date')
            ->whereNotNull('end_date')
            ->whereDate('start_date', '<=', $windowEndStr)
            ->whereDate('end_date', '>=', $cutoffStr)
            ->with(['vendor', 'project.client', 'project.latestStatus'])
            ->orderBy('start_date')
            ->orderBy('end_date')
            ->get();

        $allUserIds = $tasks
            ->pluck('user_ids')
            ->flatten()
            ->filter()
            ->unique()
            ->values();

        $usersById = $allUserIds->isNotEmpty()
            ? User::query()->whereIn('id', $allUserIds)->get()->keyBy('id')
            : collect();

        foreach ($tasks as $task) {
            $assignedUsers = collect($task->user_ids ?? [])
                ->map(fn ($userId) => $usersById->get($userId))
                ->filter()
                ->values();

            $task->setRelation('users', $assignedUsers);
        }

        $grouped = collect();

        $todayStr = $today->format('Y-m-d');

        foreach ($tasks as $task) {
            $selectedDates = (array) data_get($task->options, 'dates', []);

            if (! empty($selectedDates)) {
                foreach ($selectedDates as $dateStr) {
                    if ($dateStr >= $cutoffStr && $dateStr <= $windowEndStr) {
                        if (! $grouped->has($dateStr)) {
                            $grouped[$dateStr] = collect();
                        }
                        $grouped[$dateStr]->push($task);
                    }
                }
            } else {
                $dateStr = $task->start_date->format('Y-m-d');
                if ($dateStr >= $cutoffStr && $dateStr <= $windowEndStr) {
                    if (! $grouped->has($dateStr)) {
                        $grouped[$dateStr] = collect();
                    }
                    $grouped[$dateStr]->push($task);
                }
            }
        }

        $grouped = $grouped->sortKeys();

        $grouped = $grouped->map(function ($tasks, $dateStr) {
            return $tasks->sortBy(function ($task) use ($dateStr) {
                $startTime = (string) data_get($task->options, "time_settings.$dateStr.start_time", '');
                $usesTime = (bool) data_get($task->options, "time_settings.$dateStr.use_time", false);
                $hasTime = $usesTime && $startTime !== '';

                return $hasTime ? '0_' . $startTime : '1';
            })->values();
        });

        // Ensure the near-term window (yesterday through 6 days ahead) has visible buckets.
        for ($i = -1; $i < 7; $i++) {
            $dateStr = $today->copy()->addDays($i)->format('Y-m-d');
            if (! $grouped->has($dateStr)) {
                $grouped[$dateStr] = collect();
            }
        }

        // Keep a 14-day display window (7 days back through 6 days ahead).
        $windowStartStr = $today->copy()->subDays(7)->format('Y-m-d');
        $grouped = $grouped->filter(fn ($tasks, $date) => $date >= $windowStartStr && $date <= $windowEndStr);

        return $grouped->sortKeys();
    }

    protected function laterTasksBaseQuery(): Builder
    {
        $projectIds = $this->getProjectIds();

        return Task::withTrashed()
            ->whereIn('project_id', $projectIds)
            ->whereNotNull('start_date')
            ->whereNotNull('end_date');
    }

    protected function laterTasksWindowEnd(): string
    {
        return browser_today()->addDays(6)->format('Y-m-d');
    }

    /**
     * Get total task count for the badge.
     */
    #[Computed]
    public function taskCount(): int
    {
        $today = browser_today();
        $startOfWindow = $today->copy()->subDays(7)->format('Y-m-d');
        $endOfWindow = $today->copy()->addDays(6)->format('Y-m-d');

        $projectIds = $this->getProjectIds();

        if ($projectIds->isEmpty()) {
            return 0;
        }

        return Task::withTrashed()
            ->whereIn('project_id', $projectIds)
            ->whereNotNull('start_date')
            ->whereNotNull('end_date')
            ->whereDate('start_date', '<=', $endOfWindow)
            ->whereDate('end_date', '>=', $startOfWindow)
            ->count();
    }

    /**
     * Get tasks with no scheduled dates across client projects.
     *
     * @return Collection<int, Task>
     */
    #[Computed]
    public function unscheduledTasks(): Collection
    {
        $projectIds = $this->getProjectIds();

        if ($projectIds->isEmpty()) {
            return collect();
        }

        return Task::withTrashed()
            ->whereIn('project_id', $projectIds)
            ->whereNull('start_date')
            ->with(['vendor', 'project.client', 'project.latestStatus'])
            ->orderBy('created_at')
            ->get();
    }

    public function render()
    {
        return view('livewire.clients.upcoming-client-tasks');
    }

    /**
     * Count distinct projects that have tasks in the displayed window.
     */
    #[Computed]
    public function distinctProjectCount(): int
    {
        return $this->groupedTasks
            ->flatten(1)
            ->pluck('project_id')
            ->unique()
            ->count();
    }

    public function placeholder(array $params = [])
    {
        // A client with no projects can't have tasks — skip the skeleton
        // entirely rather than shimmering rows that resolve to an empty card.
        $client = $params['client'] ?? null;
        $hasProjects = $client instanceof Client
            ? $client->projects()->exists()
            : true;

        $isClientUser = (bool) auth()->user()?->is_browsing_as_client;

        return view('livewire.partials.tasks-placeholder', [
            'showProjectInfo' => true,
            'count' => $hasProjects ? null : 0,
            // Mirror the real card's header exactly (see the blade).
            'clientId' => $client instanceof Client ? $client->id : null,
            'showAddTask' => ! $isClientUser,
            'clickable' => ! $isClientUser,
        ]);
    }
}
