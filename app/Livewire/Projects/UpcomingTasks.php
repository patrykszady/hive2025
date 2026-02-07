<?php

namespace App\Livewire\Projects;

use App\Livewire\Concerns\HasToJsonMethod;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

class UpcomingTasks extends Component
{
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
        $windowEnd = $today->copy()->addDays(4);

        $cutoffStr = $cutoff->format('Y-m-d');
        $windowEndStr = $windowEnd->format('Y-m-d');

        $tasks = Task::query()
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

        // Ensure 5 consecutive days starting from today
        for ($i = 0; $i < 5; $i++) {
            $dateStr = $today->copy()->addDays($i)->format('Y-m-d');
            if (! $grouped->has($dateStr)) {
                $grouped[$dateStr] = collect();
            }
        }

        // Keep only the 5-day window (today through 4 days from now)
        $grouped = $grouped->filter(fn ($tasks, $date) => $date >= $todayStr && $date <= $windowEndStr);

        return $grouped->sortKeys();
    }

    /**
     * Get info about the next task after the displayed window
     */
    #[Computed]
    public function nextTaskInfo(): ?object
    {
        $today = browser_today();
        $windowEnd = $today->copy()->addDays(4);
        $windowEndStr = $windowEnd->format('Y-m-d');

        // Get all tasks for this project beyond the displayed window
        $tasks = Task::query()
            ->where('project_id', $this->project->id)
            ->whereNotNull('start_date')
            ->whereNotNull('end_date')
            ->whereDate('start_date', '>', $windowEndStr)
            ->get();

        // Collect all task dates beyond the displayed window
        $futureDates = collect();
        foreach ($tasks as $task) {
            $selectedDates = (array) data_get($task->options, 'dates', []);
            if (! empty($selectedDates)) {
                foreach ($selectedDates as $dateStr) {
                    if ($dateStr > $windowEndStr) {
                        $futureDates->push($dateStr);
                    }
                }
            } else {
                $taskStartStr = $task->start_date->format('Y-m-d');
                if ($taskStartStr > $windowEndStr) {
                    $futureDates->push($taskStartStr);
                }
            }
        }

        if ($futureDates->isEmpty()) {
            return null;
        }

        // Get the earliest future date
        $nextDateStr = $futureDates->sort()->first();
        $nextDate = Carbon::parse($nextDateStr, $this->getProjectTimezone())->startOfDay();
        
        // Calculate weekdays from the day after window end to the next task date (inclusive)
        $daysUntil = 0;
        $current = $windowEnd->copy()->addDay()->startOfDay();
        while ($current->lte($nextDate)) {
            if (! $current->isWeekend()) {
                $daysUntil++;
            }
            $current->addDay();
        }

        if ($daysUntil <= 0) {
            return null;
        }

        return (object) [
            'days' => $daysUntil,
            'label' => $daysUntil === 1 ? 'Next task in 1 day' : "Next task in {$daysUntil} days",
            'date' => $nextDate->format('D, M j'),
        ];
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

        return Task::query()
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
        return Task::query()
            ->where('project_id', $this->project->id)
            ->whereNull('start_date')
            ->with('vendor')
            ->orderBy('created_at')
            ->get();
    }

    public function render()
    {
        return view('livewire.projects.upcoming-tasks');
    }

    public function placeholder()
    {
        return view('livewire.projects.upcoming-tasks-placeholder');
    }
}
