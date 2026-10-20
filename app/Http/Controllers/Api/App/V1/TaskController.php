<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\StoreTaskRequest;
use App\Http\Requests\Api\App\UpdateTaskRequest;
use App\Http\Resources\Api\App\TaskResource;
use App\Jobs\CreateMeetTaskCalendarEvent;
use App\Jobs\UpdateMeetTaskCalendarEvent;
use App\Models\ProjectActionItem;
use App\Models\Task;
use App\Services\Tasks\TaskWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

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

        return response()->json(['data' => (new TaskResource($found))->toArray($request)]);
    }

    /**
     * Mirrors App\Livewire\Tasks\TaskCreate::save(): the write itself, then
     * (when opened from a planner action item) marking that item converted,
     * then — for a Meet with a start date — the calendar event. Simple
     * dependencies only: this accepts `parent_task_id` (TaskForm's own
     * field, used for the sibling-overlap check); the separate
     * predecessor/lag/type Gantt-dependency editor
     * (TaskCreate::$pendingDependencies / App\Models\TaskDependency) is
     * web-only.
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
