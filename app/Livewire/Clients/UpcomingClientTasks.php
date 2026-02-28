<?php

namespace App\Livewire\Clients;

use App\Models\Client;
use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

class UpcomingClientTasks extends Component
{
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
        $cutoff = $today->copy()->subDay();
        $windowEnd = $today->copy()->addDays(6);

        $cutoffStr = $cutoff->format('Y-m-d');
        $windowEndStr = $windowEnd->format('Y-m-d');

        $projectIds = $this->getProjectIds();

        if ($projectIds->isEmpty()) {
            return collect();
        }

        $tasks = Task::query()
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

    /**
     * Get info about the next task after the displayed week.
     */
    #[Computed]
    public function nextTaskInfo(): ?object
    {
        $today = browser_today();
        $windowEnd = $today->copy()->addDays(6);
        $windowEndStr = $windowEnd->format('Y-m-d');

        $projectIds = $this->getProjectIds();

        if ($projectIds->isEmpty()) {
            return null;
        }

        $tasks = Task::query()
            ->whereIn('project_id', $projectIds)
            ->whereNotNull('start_date')
            ->whereNotNull('end_date')
            ->whereDate('start_date', '>', $windowEndStr)
            ->get();

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

        $nextDateStr = $futureDates->sort()->first();
        $nextDate = Carbon::parse($nextDateStr, (string) config('app.timezone'))->startOfDay();

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
     * Get total task count for the badge.
     */
    #[Computed]
    public function taskCount(): int
    {
        $today = browser_today();
        $startOfWindow = $today->format('Y-m-d');
        $endOfWindow = $today->copy()->addDays(6)->format('Y-m-d');

        $projectIds = $this->getProjectIds();

        if ($projectIds->isEmpty()) {
            return 0;
        }

        return Task::query()
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

        return Task::query()
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

    public function placeholder()
    {
        return view('livewire.clients.upcoming-client-tasks-placeholder');
    }
}
