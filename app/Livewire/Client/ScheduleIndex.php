<?php

namespace App\Livewire\Client;

use App\Models\Project;
use App\Models\Task;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

class ScheduleIndex extends Component
{
    protected $listeners = ['refreshComponent' => '$refresh'];

    #[Locked]
    public string $token = '';

    #[Locked]
    public ?int $projectId = null;

    public bool $valid = false;
    public string $message = '';

    public function mount(string $token): void
    {
        $this->token = $token;

        $project = Project::where('schedule_token', $token)->first();

        if (! $project) {
            $this->valid = false;
            $this->message = 'This link is no longer valid.';

            return;
        }

        $this->valid = true;
        $this->projectId = $project->id;
    }

    public function getProject()
    {
        if (! $this->projectId) {
            return null;
        }

        return Project::with(['client', 'createdByVendor'])->find($this->projectId);
    }

    /**
     * Get the timezone for this project's vendor.
     */
    protected function getProjectTimezone(): string
    {
        $project = $this->getProject();
        $vendor = $project?->createdByVendor;

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
     * Get today's date for the view (for Today/Tomorrow badges).
     * Uses browser date if available, otherwise falls back to project vendor timezone.
     */
    protected function getBrowserToday(): Carbon
    {
        $browserDate = browser_date();
        
        if ($browserDate) {
            return Carbon::createFromFormat('Y-m-d', $browserDate)->startOfDay();
        }

        // Fallback to project's vendor timezone (not UTC)
        return Carbon::today($this->getProjectTimezone());
    }

    /**
     * Get today's date string (Y-m-d) for the view.
     * Uses browser timezone so "Today" badge reflects the user's local time.
     */
    #[Computed]
    public function todayDate(): string
    {
        return $this->getBrowserToday()->format('Y-m-d');
    }

    /**
     * Get tomorrow's date string (Y-m-d) for the view.
     * Uses browser timezone so "Tomorrow" badge reflects the user's local time.
     */
    #[Computed]
    public function tomorrowDate(): string
    {
        return $this->getBrowserToday()->addDay()->format('Y-m-d');
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
     * Get tasks grouped by date, with multi-day tasks appearing on each day.
     * 
     * @return Collection<string, Collection<int, Task>>
     */
    #[Computed]
    public function groupedTasks(): Collection
    {
        if (! $this->projectId) {
            return collect();
        }

        $today = $this->getToday();

        // Build grouped tasks with all days in range (including empty days)
        $grouped = collect();
        $todayCarbon = Carbon::parse($today);

        // Show from start of week (Monday) through end of current week (Sunday)
        $startOfWeek = $todayCarbon->copy()->startOfWeek(Carbon::MONDAY);
        $endOfWeek = $todayCarbon->copy()->endOfWeek(Carbon::SUNDAY);
        $startOfWeekStr = $startOfWeek->format('Y-m-d');
        $endOfWeekStr = $endOfWeek->format('Y-m-d');

        // Get tasks that have any date within the current week
        // Don't filter by end_date >= today, as tasks earlier in the week should still appear
        $tasks = Task::query()
            ->where('project_id', $this->projectId)
            ->whereNotNull('start_date')
            ->whereNotNull('end_date')
            ->where(function ($query) use ($startOfWeekStr, $endOfWeekStr) {
                // Task overlaps with the current week
                $query->whereDate('start_date', '<=', $endOfWeekStr)
                      ->whereDate('end_date', '>=', $startOfWeekStr);
            })
            ->orderBy('start_date')
            ->orderBy('end_date')
            ->get();

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
     * Get info about the next task after the displayed week.
     */
    #[Computed]
    public function nextTaskInfo(): ?object
    {
        if (! $this->projectId) {
            return null;
        }

        $today = $this->getToday();
        $todayStr = $today->format('Y-m-d');
        $weekEnd = $today->copy()->endOfWeek(Carbon::SUNDAY);
        $weekEndStr = $weekEnd->format('Y-m-d');

        $tasks = Task::query()
            ->where('project_id', $this->projectId)
            ->whereNotNull('start_date')
            ->whereNotNull('end_date')
            ->whereDate('end_date', '>=', $today)
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
     * Get total task count for the badge.
     */
    #[Computed]
    public function taskCount(): int
    {
        if (! $this->projectId) {
            return 0;
        }

        $today = $this->getToday();

        return Task::query()
            ->where('project_id', $this->projectId)
            ->whereNotNull('start_date')
            ->whereNotNull('end_date')
            ->whereDate('end_date', '>=', $today)
            ->count();
    }

    /**
     * Get unscheduled tasks (tasks without dates selected).
     */
    #[Computed]
    public function unscheduledTasks(): Collection
    {
        if (! $this->projectId) {
            return collect();
        }

        return Task::query()
            ->where('project_id', $this->projectId)
            ->whereNull('start_date')
            ->orderBy('created_at')
            ->get();
    }

    public function render()
    {
        return view('livewire.client.schedule-index')
            ->layout('components.layouts.guest', [
                'title' => 'Schedule',
            ]);
    }
}
