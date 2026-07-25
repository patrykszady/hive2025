<?php

namespace App\Livewire\Projects;

use App\Livewire\Concerns\HasLaterTasks;
use App\Livewire\Concerns\HasToJsonMethod;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

class UpcomingTasks extends Component
{
    use HasLaterTasks;
    use HasToJsonMethod;

    protected $listeners = ['refreshComponent' => '$refresh'];

    public Project $project;

    /**
     * Get the timezone for this project's vendor.
     */
    protected function getProjectTimezone(): string
    {
        $vendor = $this->project->createdByVendor;

        if ($vendor && is_string($vendor->timezone) && $vendor->timezone !== '') {
            return $vendor->timezone;
        }

        return (string) config('app.timezone');
    }

    /**
     * Get today's date in the project's vendor timezone.
     */
    protected function getToday(): Carbon
    {
        return Carbon::today($this->getProjectTimezone());
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
     * Get tasks grouped by date, with multi-day tasks appearing on each day
     * 
     * @return Collection<string, Collection<int, Task>>
     */
    #[Computed]
    public function groupedTasks(): Collection
    {
        $today = browser_today();
        $cutoff = $today->copy()->subDay();
        $windowEnd = $today->copy()->addDays(6);

        $cutoffStr = $cutoff->format('Y-m-d');
        $windowEndStr = $windowEnd->format('Y-m-d');

        $tasks = Task::withTrashed()
            ->where('project_id', $this->project->id)
            ->whereNotNull('start_date')
            ->whereNotNull('end_date')
            ->whereDate('start_date', '<=', $windowEndStr)
            ->whereDate('end_date', '>=', $cutoffStr)
            ->with('vendor')
            ->orderBy('start_date')
            ->orderBy('end_date')
            ->get();

        // Eager load users
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

        // Group tasks by their selected dates
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
                // Fallback: single-day task using start_date
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

        // Sort tasks within each day: tasks with start_time first (earliest to latest), then tasks without
        $grouped = $grouped->map(function ($tasks, $dateStr) {
            return $tasks->sortBy(function ($task) use ($dateStr) {
                $startTime = (string) data_get($task->options, "time_settings.$dateStr.start_time", '');
                $usesTime = (bool) data_get($task->options, "time_settings.$dateStr.use_time", false);
                $hasTime = $usesTime && $startTime !== '';

                return $hasTime ? '0_' . $startTime : '1';
            })->values();
        });

        // Ensure 8 consecutive days starting from yesterday (browser timezone)
        for ($i = -1; $i < 7; $i++) {
            $dateStr = $today->copy()->addDays($i)->format('Y-m-d');
            if (! $grouped->has($dateStr)) {
                $grouped[$dateStr] = collect();
            }
        }

        // Keep only the 8-day window (yesterday through 6 days from now)
        $windowStartStr = $today->copy()->subDay()->format('Y-m-d');
        $grouped = $grouped->filter(fn ($tasks, $date) => $date >= $windowStartStr && $date <= $windowEndStr);

        return $grouped->sortKeys();
    }

    protected function laterTasksBaseQuery(): Builder
    {
        return Task::withTrashed()
            ->where('project_id', $this->project->id)
            ->whereNotNull('start_date')
            ->whereNotNull('end_date');
    }

    protected function laterTasksWindowEnd(): string
    {
        return browser_today()->addDays(6)->format('Y-m-d');
    }

    /**
     * Get total task count for the badge
     */
    #[Computed]
    public function taskCount(): int
    {
        $today = browser_today();
        $cutoff = $today->copy()->subDay();
        $windowEnd = $today->copy()->addDays(4);

        return Task::withTrashed()
            ->where('project_id', $this->project->id)
            ->whereNotNull('start_date')
            ->whereNotNull('end_date')
            ->whereDate('start_date', '<=', $windowEnd)
            ->whereDate('end_date', '>=', $cutoff)
            ->count();
    }

    /**
     * Get tasks that have no scheduled dates
     *
     * @return Collection<int, Task>
     */
    #[Computed]
    public function unscheduledTasks(): Collection
    {
        $tasks = Task::withTrashed()
            ->where('project_id', $this->project->id)
            ->whereNull('start_date')
            ->with('vendor')
            ->orderBy('created_at')
            ->get();

        $allUserIds = $tasks
            ->pluck('user_ids')
            ->flatten()
            ->filter()
            ->unique()
            ->values();

        if ($allUserIds->isNotEmpty()) {
            $usersById = User::query()->whereIn('id', $allUserIds)->get()->keyBy('id');

            foreach ($tasks as $task) {
                $assignedUsers = collect($task->user_ids ?? [])
                    ->map(fn ($userId) => $usersById->get($userId))
                    ->filter()
                    ->values();

                $task->setRelation('users', $assignedUsers);
            }
        }

        return $tasks;
    }

    public function render()
    {
        return view('livewire.projects.upcoming-tasks');
    }

    public function placeholder(array $params = [])
    {
        $isClientUser = (bool) auth()->user()?->is_browsing_as_client;
        $project = $params['project'] ?? null;

        return view('livewire.partials.tasks-placeholder', [
            // Mirror the real card's header exactly (see the blade).
            'projectId' => $project?->id,
            'clickable' => ! $isClientUser,
        ]);
    }
}
