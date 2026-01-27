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
     * Get the start of week (Monday) date string (Y-m-d) for the view.
     */
    #[Computed]
    public function startOfWeekDate(): string
    {
        return $this->getToday()->startOfWeek(Carbon::MONDAY)->format('Y-m-d');
    }

    /**
     * Get tasks grouped by date, with multi-day tasks appearing on each day
     * 
     * @return Collection<string, Collection<int, Task>>
     */
    #[Computed]
    public function groupedTasks(): Collection
    {
        $today = $this->getToday();

        // Show tasks for the *current week* (Mon-Sun), including earlier days.
        // This keeps the weekly view useful mid-week (e.g. a task on Tuesday still shows on Wednesday).
        $todayCarbon = Carbon::parse($today);
        $startOfWeek = $todayCarbon->copy()->startOfWeek(Carbon::MONDAY);
        $endOfWeek = $todayCarbon->copy()->endOfWeek(Carbon::SUNDAY);

        $startOfWeekStr = $startOfWeek->format('Y-m-d');
        $endOfWeekStr = $endOfWeek->format('Y-m-d');

        $tasks = Task::query()
            ->where('project_id', $this->project->id)
            ->whereNotNull('start_date')
            ->whereNotNull('end_date')
            ->whereDate('start_date', '<=', $endOfWeekStr)
            ->whereDate('end_date', '>=', $startOfWeekStr)
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

        // Build grouped tasks with all days in range (including empty days)
        $grouped = collect();

        $startDate = $startOfWeek->copy();
        $endDate = $endOfWeek->copy();

        // Create all days in the range (Monday through Sunday)
        $currentDate = $startDate->copy();
        while ($currentDate->lte($endDate)) {
            $grouped[$currentDate->format('Y-m-d')] = collect();
            $currentDate->addDay();
        }

        // Add tasks to their specifically selected dates (only within the week)
        foreach ($tasks as $task) {
            $selectedDates = (array) data_get($task->options, 'dates', []);
            
            if (! empty($selectedDates)) {
                foreach ($selectedDates as $dateStr) {
                    if ($dateStr >= $startOfWeekStr && $dateStr <= $endOfWeekStr && $grouped->has($dateStr)) {
                        $grouped[$dateStr]->push($task);
                    }
                }
            } else {
                // Fallback: single-day task using start_date
                $dateStr = $task->start_date->format('Y-m-d');
                if ($dateStr >= $startOfWeekStr && $dateStr <= $endOfWeekStr && $grouped->has($dateStr)) {
                    $grouped[$dateStr]->push($task);
                }
            }
        }

        // Sort by date
        return $grouped->sortKeys();
    }

    /**
     * Get info about the next task after the displayed week
     */
    #[Computed]
    public function nextTaskInfo(): ?object
    {
        $today = $this->getToday();
        $weekEnd = $today->copy()->endOfWeek(Carbon::SUNDAY);
        $weekEndStr = $weekEnd->format('Y-m-d');

        // Get all tasks for this project
        $tasks = Task::query()
            ->where('project_id', $this->project->id)
            ->whereNotNull('start_date')
            ->whereNotNull('end_date')
            ->whereDate('start_date', '>', $weekEndStr)
            ->get();

        // Collect all task dates beyond the displayed week
        $futureDates = collect();
        foreach ($tasks as $task) {
            $selectedDates = (array) data_get($task->options, 'dates', []);
            if (! empty($selectedDates)) {
                foreach ($selectedDates as $dateStr) {
                    if ($dateStr > $weekEndStr) {
                        $futureDates->push($dateStr);
                    }
                }
            } else {
                $taskStartStr = $task->start_date->format('Y-m-d');
                if ($taskStartStr > $weekEndStr) {
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
        
        // Calculate weekdays from the day after Sunday to the next task date (inclusive)
        $daysUntil = 0;
        $current = $weekEnd->copy()->addDay()->startOfDay(); // Start from Monday after Sunday
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
        $today = $this->getToday();

        $startOfWeek = $today->copy()->startOfWeek(Carbon::MONDAY)->format('Y-m-d');
        $endOfWeek = $today->copy()->endOfWeek(Carbon::SUNDAY)->format('Y-m-d');

        return Task::query()
            ->where('project_id', $this->project->id)
            ->whereNotNull('start_date')
            ->whereNotNull('end_date')
            ->whereDate('start_date', '<=', $endOfWeek)
            ->whereDate('end_date', '>=', $startOfWeek)
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
}
