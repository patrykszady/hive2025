<?php

namespace App\Livewire\Dashboard;

use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

class UserTasks extends Component
{
    protected $listeners = ['refreshComponent' => '$refresh'];

    /**
     * Get tasks grouped by date, matching the format expected by upcoming-tasks-list component.
     *
     * @return Collection<string, Collection<int, Task>>
     */
    #[Computed]
    public function groupedTasks(): Collection
    {
        $userId = (string) auth()->id();
        $today = browser_today();
        $cutoff = $today->copy()->subDay();

        $tasks = Task::whereJsonContains('user_ids', $userId)
            ->where(function ($query) use ($cutoff) {
                $query->whereDate('end_date', '>=', $cutoff);
            })
            ->whereNotNull('start_date')
            ->whereNotNull('end_date')
            ->with(['project.client', 'project.latestStatus', 'vendor'])
            ->orderBy('start_date')
            ->orderBy('end_date')
            ->limit(20)
            ->get();

        // Eager load users for the tasks
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
                ->map(fn ($id) => $usersById->get($id))
                ->filter()
                ->values();

            $task->setRelation('users', $assignedUsers);
        }

        // Group tasks by their selected dates
        $grouped = collect();

        foreach ($tasks as $task) {
            $selectedDates = (array) data_get($task->options, 'dates', []);

            if (! empty($selectedDates)) {
                foreach ($selectedDates as $dateStr) {
                    if ($dateStr >= $cutoff->format('Y-m-d')) {
                        if (! $grouped->has($dateStr)) {
                            $grouped[$dateStr] = collect();
                        }
                        $grouped[$dateStr]->push($task);
                    }
                }
            } else {
                // Fallback to start_date
                $dateStr = $task->start_date->format('Y-m-d');
                if ($dateStr >= $cutoff->format('Y-m-d')) {
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

                // Tasks with time sort first (0), then by time string; tasks without time sort last (1)
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
        $windowStart = $today->copy()->subDay()->format('Y-m-d');
        $windowEnd = $today->copy()->addDays(6)->format('Y-m-d');
        $grouped = $grouped->filter(fn ($tasks, $date) => $date >= $windowStart && $date <= $windowEnd);

        return $grouped->sortKeys();
    }

    /**
     * Get total task count for the badge.
     */
    #[Computed]
    public function taskCount(): int
    {
        $userId = (string) auth()->id();
        $today = browser_today();
        $cutoff = $today->copy()->subDay();
        $windowEnd = $today->copy()->addDays(6)->format('Y-m-d');

        return Task::whereJsonContains('user_ids', $userId)
            ->whereDate('end_date', '>=', $cutoff)
            ->whereDate('start_date', '<=', $windowEnd)
            ->whereNotNull('start_date')
            ->whereNotNull('end_date')
            ->count();
    }

    public function render()
    {
        return view('livewire.dashboard.user-tasks');
    }

    public function placeholder(): \Illuminate\Contracts\View\View
    {
        return view('livewire.dashboard.user-tasks-placeholder');
    }
}
