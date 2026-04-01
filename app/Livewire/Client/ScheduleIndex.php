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

    #[Locked]
    public ?int $clientId = null;

    public bool $valid = false;
    public string $message = '';

    public function mount(string $token): void
    {
        // Logged-in users go straight to the dashboard
        if (auth()->check()) {
            $this->redirect(url('/'));
            return;
        }

        $this->token = $token;

        $project = Project::where('schedule_token', $token)->first();

        if (! $project) {
            $this->valid = false;
            $this->message = 'This link is no longer valid.';

            return;
        }

        $this->valid = true;
        $this->projectId = $project->id;
        $this->clientId = $project->client_id;
    }

    /**
     * Get all project IDs for this client.
     *
     * @return array<int>
     */
    #[Computed]
    public function clientProjectIds(): array
    {
        if ($this->clientId) {
            return Project::where('client_id', $this->clientId)->pluck('id')->all();
        }

        return $this->projectId ? [$this->projectId] : [];
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
     * Get the start date string (Y-m-d) for the view (2 days before today).
     */
    #[Computed]
    public function startOfWeekDate(): string
    {
        return $this->getToday()->subDays(2)->format('Y-m-d');
    }

    /**
     * Get tasks grouped by date, with multi-day tasks appearing on each day.
     * 
     * @return Collection<string, Collection<int, Task>>
     */
    #[Computed]
    public function groupedTasks(): Collection
    {
        $projectIds = $this->clientProjectIds;

        if (empty($projectIds)) {
            return collect();
        }

        $today = $this->getToday();

        // Build grouped tasks with all days in range (including empty days)
        $grouped = collect();
        $todayCarbon = Carbon::parse($today);

        // Show from 2 days before today through end of current week (Sunday)
        $startDate = $todayCarbon->copy()->subDays(2);
        $endOfWeek = $todayCarbon->copy()->endOfWeek(Carbon::SUNDAY);
        // Ensure at least 5 days shown after today
        $endDate = $endOfWeek->max($todayCarbon->copy()->addDays(5));
        $startDateStr = $startDate->format('Y-m-d');
        $endDateStr = $endDate->format('Y-m-d');

        // Get tasks that have any date within the range
        // Don't filter by end_date >= today, as tasks earlier in the range should still appear
        $tasks = Task::withTrashed()
            ->whereIn('project_id', $projectIds)
            ->whereNotNull('start_date')
            ->whereNotNull('end_date')
            ->where(function ($query) use ($startDateStr, $endDateStr) {
                // Task overlaps with the display range
                $query->whereDate('start_date', '<=', $endDateStr)
                      ->whereDate('end_date', '>=', $startDateStr);
            })
            ->orderBy('start_date')
            ->orderBy('end_date')
            ->get();

        // Create all days in the range
        $currentDate = $startDate->copy();
        while ($currentDate->lte($endDate)) {
            $grouped[$currentDate->format('Y-m-d')] = collect();
            $currentDate->addDay();
        }

        // Add tasks to their specifically selected dates (only within the range)
        foreach ($tasks as $task) {
            $selectedDates = (array) data_get($task->options, 'dates', []);
            
            if (! empty($selectedDates)) {
                foreach ($selectedDates as $dateStr) {
                    if ($dateStr >= $startDateStr && $dateStr <= $endDateStr && $grouped->has($dateStr)) {
                        $grouped[$dateStr]->push($task);
                    }
                }
            } else {
                // Fallback: single-day task using start_date
                $dateStr = $task->start_date->format('Y-m-d');
                if ($dateStr >= $startDateStr && $dateStr <= $endDateStr && $grouped->has($dateStr)) {
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
        $projectIds = $this->clientProjectIds;

        if (empty($projectIds)) {
            return null;
        }

        $today = $this->getToday();
        $todayStr = $today->format('Y-m-d');
        $endOfWeek = $today->copy()->endOfWeek(Carbon::SUNDAY);
        $displayEnd = $endOfWeek->max($today->copy()->addDays(5));
        $displayEndStr = $displayEnd->format('Y-m-d');

        $tasks = Task::withTrashed()
            ->whereIn('project_id', $projectIds)
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
                    if ($dateStr > $displayEndStr) {
                        $futureDates->push($dateStr);
                    }
                }
            } else {
                $taskStartStr = $task->start_date->format('Y-m-d');
                if ($taskStartStr > $displayEndStr) {
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
        
        $daysUntil = (int) $displayEnd->copy()->addDay()->startOfDay()->diffInDays($nextDate);

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
        $projectIds = $this->clientProjectIds;

        if (empty($projectIds)) {
            return 0;
        }

        $today = $this->getToday();

        return Task::withTrashed()
            ->whereIn('project_id', $projectIds)
            ->whereNotNull('start_date')
            ->whereNotNull('end_date')
            ->whereDate('end_date', '>=', $today)
            ->count();
    }

    /**
     * Whether the displayed tasks span multiple projects.
     */
    #[Computed]
    public function hasMultipleProjects(): bool
    {
        $projectIds = $this->groupedTasks
            ->flatten(1)
            ->pluck('project_id')
            ->merge($this->unscheduledTasks->pluck('project_id'))
            ->unique();

        return $projectIds->count() > 1;
    }

    /**
     * Get unscheduled tasks (tasks without dates selected).
     */
    #[Computed]
    public function unscheduledTasks(): Collection
    {
        $projectIds = $this->clientProjectIds;

        if (empty($projectIds)) {
            return collect();
        }

        return Task::withTrashed()
            ->whereIn('project_id', $projectIds)
            ->whereNull('start_date')
            ->orderBy('created_at')
            ->get();
    }

    public function render()
    {
        return view('livewire.client.schedule-index')
            ->layout('components.layouts.guest', [
                'title' => 'Schedule',
                'bodyClass' => 'bg-zinc-100',
            ]);
    }
}
