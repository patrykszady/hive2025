<?php

namespace App\Services\Tasks;

use App\Models\Project;
use App\Models\Task;
use App\Models\TaskDependency;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Adds and removes a task's predecessor/successor links exactly like
 * App\Livewire\Tasks\TaskCreate's Dependencies panel
 * (addDependency()/removeDependency()/availableTasks(), the "already saved"
 * branch — a brand-new, not-yet-saved task's chosen-but-pending
 * predecessors stay that component's own concern) — shared with the Hive
 * Contractors app's task screen so both write the same TaskDependency rows
 * under the same guards.
 */
class TaskDependencyWriter
{
    /**
     * @throws ValidationException
     */
    public function add(Task $task, int $predecessorId, string $type, int $lagDays): TaskDependency
    {
        // exists:tasks,id is a bare table check — Task has no tenant scope,
        // so without this any other company's task id would pass and get
        // pulled into this task's dependency list (title and all).
        if (! Task::visibleTo($predecessorId)) {
            throw ValidationException::withMessages(['predecessor_task_id' => 'That task is not available.']);
        }

        if ($predecessorId === $task->id) {
            throw ValidationException::withMessages(['predecessor_task_id' => 'A task cannot depend on itself.']);
        }

        if (TaskDependency::wouldCreateCircularDependency($predecessorId, $task->id)) {
            throw ValidationException::withMessages(['predecessor_task_id' => 'This would create a circular dependency.']);
        }

        $exists = TaskDependency::where('predecessor_task_id', $predecessorId)
            ->where('successor_task_id', $task->id)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['predecessor_task_id' => 'This dependency already exists.']);
        }

        return TaskDependency::create([
            'predecessor_task_id' => $predecessorId,
            'successor_task_id' => $task->id,
            'type' => $type,
            'lag_days' => $lagDays,
        ]);
    }

    /**
     * Must actually belong to $task (predecessor or successor side) —
     * $dependencyId is otherwise a bare id the caller could pass for any
     * dependency row. Returns false (a silent no-op, same as the web) when
     * it doesn't.
     */
    public function remove(Task $task, int $dependencyId): bool
    {
        $dependency = TaskDependency::where('id', $dependencyId)
            ->where(function ($query) use ($task) {
                $query->where('predecessor_task_id', $task->id)
                    ->orWhere('successor_task_id', $task->id);
            })
            ->first();

        if (! $dependency) {
            return false;
        }

        $dependency->delete();

        return true;
    }

    /**
     * Candidate predecessors: scheduled tasks on the same project, minus
     * the task itself and its existing predecessors (and, for the web's
     * not-yet-saved case, any already chosen in this session — see
     * TaskCreate::availableTasks(), which this mirrors for a saved task).
     *
     * `$excludeTaskId`'s own project is used for the query, so a
     * client-writable project id elsewhere is never trusted blindly —
     * mirrors TaskCreate::availableTasks()'s own tenant check.
     *
     * @param  list<int>  $extraExcludeIds
     * @return Collection<int, Task>
     */
    public function availableTasks(int $projectId, ?int $excludeTaskId, array $extraExcludeIds = []): Collection
    {
        if (! Project::query()->whereKey($projectId)->exists()) {
            return new Collection();
        }

        $excludeIds = array_filter([$excludeTaskId]);

        if ($excludeTaskId) {
            $task = Task::find($excludeTaskId);

            if ($task) {
                $excludeIds = array_merge($excludeIds, $task->predecessorTasks->pluck('id')->toArray());
            }
        }

        $excludeIds = array_merge($excludeIds, $extraExcludeIds);

        return Task::where('project_id', $projectId)
            ->whereNotIn('id', $excludeIds)
            ->whereNotNull('start_date')
            ->whereNotNull('end_date')
            ->orderBy('start_date')
            ->get();
    }
}
