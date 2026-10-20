<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\StoreTaskDependencyRequest;
use App\Http\Requests\Api\App\StoreTaskRequest;
use App\Http\Requests\Api\App\UpdateTaskRequest;
use App\Http\Resources\Api\App\TaskResource;
use App\Jobs\CreateMeetTaskCalendarEvent;
use App\Jobs\UpdateMeetTaskCalendarEvent;
use App\Models\ProjectActionItem;
use App\Models\Task;
use App\Services\Tasks\TaskDependencyWriter;
use App\Services\Tasks\TaskWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The app's task screens: create, edit, delete and schedule a task exactly
 * like App\Livewire\Tasks\TaskCreate — see App\Services\Tasks\TaskWriter
 * for the shared record-writing logic. Every Task::create()/update() call
 * below runs through the model's own events (App\Observers\TaskObserver),
 * so the same realtime/vendor/service-call notifications the web triggers
 * fire here too.
 */
class TaskController extends Controller
{
    public function show(Request $request, int $task): JsonResponse
    {
        $found = $this->visibleTask($task);
        Gate::authorize('view', $found);

        $found->load(['predecessorDependencies.predecessor', 'successorDependencies.successor']);

        return response()->json(['data' => (new TaskResource($found))->toArray($request)]);
    }

    /**
     * Mirrors App\Livewire\Tasks\TaskCreate::save(): the write itself, then
     * (when opened from a planner action item) marking that item converted,
     * then — for a Meet with a start date — the calendar event. Accepts
     * `parent_task_id` (TaskForm's own field, used for the sibling-overlap
     * check). The predecessor/lag/type dependency editor
     * (App\Models\TaskDependency) is a separate add/remove flow once the
     * task exists — see dependencies()/storeDependency()/destroyDependency()
     * below — mirroring TaskCreate's own "pending until save" behaviour for
     * a brand-new task is left to the app's create screen, not this write.
     */
    public function store(StoreTaskRequest $request, TaskWriter $writer): JsonResponse
    {
        $task = $writer->store($request->validated());

        $actionItemId = $request->integer('action_item_id') ?: null;

        if ($actionItemId) {
            ProjectActionItem::query()
                ->whereKey($actionItemId)
                ->where('project_id', $task->project_id)
                ->update(['status' => ProjectActionItem::STATUS_TASK, 'task_id' => $task->id]);
        }

        if ($task->type === 'Meet' && $task->start_date) {
            CreateMeetTaskCalendarEvent::dispatch($task->id, auth()->id());
        }

        return response()->json(['data' => (new TaskResource($task))->toArray($request)], 201);
    }

    /** Mirrors TaskCreate::edit(): authorize, write, then the Meet calendar event (create vs. update). */
    public function update(UpdateTaskRequest $request, int $task, TaskWriter $writer): JsonResponse
    {
        $found = $this->visibleTask($task);
        Gate::authorize('update', $found);

        $wasMeetWithEvent = $found->type === 'Meet' && filled(data_get($found->options, 'nylas_meet_event.event_id'));

        $updated = $writer->update($found, $request->validated());

        if ($updated->type === 'Meet' && $updated->start_date) {
            if ($wasMeetWithEvent) {
                UpdateMeetTaskCalendarEvent::dispatch($updated->id, auth()->id());
            } else {
                CreateMeetTaskCalendarEvent::dispatch($updated->id, auth()->id());
            }
        }

        return response()->json(['data' => (new TaskResource($updated))->toArray($request)]);
    }

    /** Mirrors the web's delete: authorize, soft-delete (App\Observers\TaskObserver::deleted() handles the rest). */
    public function destroy(Request $request, int $task): JsonResponse
    {
        $found = $this->visibleTask($task);
        Gate::authorize('delete', $found);

        $found->delete();

        return response()->json(['data' => ['id' => $found->id, 'deleted' => true]]);
    }

    /**
     * Candidate predecessors for this task — mirrors
     * App\Livewire\Tasks\TaskCreate::availableTasks() via the shared
     * App\Services\Tasks\TaskDependencyWriter::availableTasks().
     */
    public function availablePredecessors(Request $request, int $task, TaskDependencyWriter $writer): JsonResponse
    {
        $found = $this->visibleTask($task);
        Gate::authorize('view', $found);

        $tasks = $writer->availableTasks((int) $found->project_id, $found->id);

        return response()->json(['data' => $tasks->map(fn (Task $candidate) => [
            'id' => $candidate->id,
            'title' => $candidate->title,
            'start_date' => $candidate->start_date?->toDateString(),
        ])->values()->all()]);
    }

    /**
     * Add a predecessor — mirrors
     * App\Livewire\Tasks\TaskCreate::addDependency()'s "already saved"
     * branch, via the shared App\Services\Tasks\TaskDependencyWriter::add().
     */
    public function storeDependency(StoreTaskDependencyRequest $request, int $task, TaskDependencyWriter $writer): JsonResponse
    {
        $found = $this->visibleTask($task);
        Gate::authorize('update', $found);

        try {
            $writer->add(
                $found,
                (int) $request->validated('predecessor_task_id'),
                (string) $request->validated('type'),
                (int) $request->validated('lag_days', 0),
            );
        } catch (ValidationException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'errors' => $exception->errors()], 422);
        }

        $found->load(['predecessorDependencies.predecessor', 'successorDependencies.successor']);

        return response()->json(['data' => (new TaskResource($found))->toArray($request)], 201);
    }

    /**
     * Remove a dependency — mirrors
     * App\Livewire\Tasks\TaskCreate::removeDependency(), via the shared
     * App\Services\Tasks\TaskDependencyWriter::remove().
     */
    public function destroyDependency(Request $request, int $task, int $dependency, TaskDependencyWriter $writer): JsonResponse
    {
        $found = $this->visibleTask($task);
        Gate::authorize('update', $found);

        $writer->remove($found, $dependency);

        $found->load(['predecessorDependencies.predecessor', 'successorDependencies.successor']);

        return response()->json(['data' => (new TaskResource($found))->toArray($request)]);
    }

    /**
     * A task the signed-in person may see: one on a project this tenant can
     * access. Task carries no global scope of its own — Task::visibleTo()
     * is the same check App\Livewire\Planner\CardsIndex::visibleTask() and
     * TaskPolicy::view() rely on. 404 for a nonexistent task, 403 for one on
     * a project outside this vendor.
     */
    private function visibleTask(int $id): Task
    {
        abort_unless(Task::withTrashed()->whereKey($id)->exists(), 404);

        $found = Task::visibleTo($id);
        abort_unless($found, 403, 'You do not have access to this task.');

        return $found;
    }
}
