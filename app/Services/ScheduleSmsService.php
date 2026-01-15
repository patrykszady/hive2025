<?php

namespace App\Services;

use App\Models\Task;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ScheduleSmsService
{
    /**
     * Get tasks for a target date with optional filters and relations.
     *
     * @param  array<int, string>  $with
     * @param  callable(Builder):void|null  $queryCallback
     */
    public function getTasksForDate(
        Carbon $date,
        array $with = [],
        ?callable $queryCallback = null,
        bool $requireStartDateMatchIfNoSelectedDates = false
    ): Collection {
        $targetDateStr = $date->format('Y-m-d');

        $query = Task::query()
            ->with($with)
            ->whereNotNull('start_date')
            ->whereNotNull('end_date')
            ->whereDate('start_date', '<=', $targetDateStr)
            ->whereDate('end_date', '>=', $targetDateStr);

        if ($queryCallback) {
            $queryCallback($query);
        }

        return $query->get()->filter(function (Task $task) use ($targetDateStr, $requireStartDateMatchIfNoSelectedDates) {
            return $this->taskMatchesDate($task, $targetDateStr, $requireStartDateMatchIfNoSelectedDates);
        });
    }

    public function taskMatchesDate(
        Task $task,
        string $targetDateStr,
        bool $requireStartDateMatchIfNoSelectedDates = false
    ): bool {
        $selectedDates = (array) data_get($task->options, 'dates', []);

        if (! empty($selectedDates)) {
            return in_array($targetDateStr, $selectedDates, true);
        }

        if ($requireStartDateMatchIfNoSelectedDates) {
            return $task->start_date?->format('Y-m-d') === $targetDateStr;
        }

        return true;
    }

    /**
     * Build a map of user_id => ['user' => User, 'tasks' => array].
     */
    public function buildUserTaskMap(Collection $tasks, Carbon $date): array
    {
        $userTasks = [];

        foreach ($tasks as $task) {
            if ($this->shouldSkipWeekendTask($task, $date)) {
                continue;
            }

            foreach ($task->users as $user) {
                if (! $user->cell_phone) {
                    continue;
                }

                if (! isset($userTasks[$user->id])) {
                    $userTasks[$user->id] = [
                        'user' => $user,
                        'tasks' => [],
                    ];
                }

                $userTasks[$user->id]['tasks'][] = $task;
            }
        }

        return $userTasks;
    }

    /**
     * Check if a task should be skipped for weekend days based on options.
     */
    public function shouldSkipWeekendTask(Task $task, Carbon $date): bool
    {
        $dayOfWeek = $date->dayOfWeek; // 0 = Sunday, 6 = Saturday

        if ($dayOfWeek !== 0 && $dayOfWeek !== 6) {
            return false;
        }

        $options = $task->options;

        if (! $options) {
            return true;
        }

        if ($dayOfWeek === 6) {
            return ! isset($options->saturday) || $options->saturday !== true;
        }

        return ! isset($options->sunday) || $options->sunday !== true;
    }
}
