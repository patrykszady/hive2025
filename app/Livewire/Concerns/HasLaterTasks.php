<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;

trait HasLaterTasks
{
    /**
     * Get the base query for fetching tasks (applies component-specific scopes).
     */
    abstract protected function laterTasksBaseQuery(): Builder;

    /**
     * Get the window end date string (Y-m-d) beyond which tasks are considered "later".
     */
    abstract protected function laterTasksWindowEnd(): string;

    /**
     * Get tasks beyond the displayed window, grouped by date.
     *
     * @return Collection<string, Collection<int, Task>>
     */
    #[Computed]
    public function laterTasks(): Collection
    {
        $windowEndStr = $this->laterTasksWindowEnd();

        $tasks = $this->laterTasksBaseQuery()
            ->whereDate('start_date', '>', $windowEndStr)
            // The Later accordion's cards read project info and the
            // preferred-time indicator (project + latestStatus) per task.
            ->with(['vendor', 'project.client', 'project.latestStatus'])
            ->orderBy('start_date')
            ->orderBy('end_date')
            ->get();

        \App\Models\Task::primeUpdateActivities($tasks->pluck('id'));

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

        // Group tasks by their selected dates (only dates beyond the window)
        $grouped = collect();

        foreach ($tasks as $task) {
            $selectedDates = (array) data_get($task->options, 'dates', []);

            if (! empty($selectedDates)) {
                foreach ($selectedDates as $dateStr) {
                    if ($dateStr > $windowEndStr) {
                        if (! $grouped->has($dateStr)) {
                            $grouped[$dateStr] = collect();
                        }
                        $grouped[$dateStr]->push($task);
                    }
                }
            } else {
                $dateStr = $task->start_date->format('Y-m-d');
                if ($dateStr > $windowEndStr) {
                    if (! $grouped->has($dateStr)) {
                        $grouped[$dateStr] = collect();
                    }
                    $grouped[$dateStr]->push($task);
                }
            }
        }

        return $grouped->sortKeys();
    }
}
