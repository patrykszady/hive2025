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
        $today = Carbon::today();

        $tasks = Task::whereJsonContains('user_ids', $userId)
            ->where(function ($query) use ($today) {
                $query->whereDate('end_date', '>=', $today);
            })
            ->whereNotNull('start_date')
            ->whereNotNull('end_date')
            ->with(['project.client', 'vendor'])
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
                    if ($dateStr >= $today->format('Y-m-d')) {
                        if (! $grouped->has($dateStr)) {
                            $grouped[$dateStr] = collect();
                        }
                        $grouped[$dateStr]->push($task);
                    }
                }
            } else {
                // Fallback to start_date
                $dateStr = $task->start_date->format('Y-m-d');
                if ($dateStr >= $today->format('Y-m-d')) {
                    if (! $grouped->has($dateStr)) {
                        $grouped[$dateStr] = collect();
                    }
                    $grouped[$dateStr]->push($task);
                }
            }
        }

        return $grouped->sortKeys();
    }

    /**
     * Get total task count for the badge.
     */
    #[Computed]
    public function taskCount(): int
    {
        $userId = (string) auth()->id();
        $today = Carbon::today();

        return Task::whereJsonContains('user_ids', $userId)
            ->whereDate('end_date', '>=', $today)
            ->whereNotNull('start_date')
            ->whereNotNull('end_date')
            ->count();
    }

    public function render()
    {
        return view('livewire.dashboard.user-tasks');
    }
}
