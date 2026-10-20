<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\StoreTaskDependencyRequest;
use App\Http\Requests\Api\App\StoreTaskRequest;
use App\Http\Requests\Api\App\UpdateTaskRequest;
use App\Http\Resources\Api\App\TaskResource;
use App\Jobs\CreateMeetTaskCalendarEvent;
use App\Jobs\UpdateMeetTaskCalendarEvent;
use App\Models\Project;
use App\Models\ProjectActionItem;
use App\Models\Task;
use App\Models\Vendor;
use App\Services\Tasks\MeetingContactsFinder;
use App\Services\Tasks\TaskDependencyWriter;
use App\Services\Tasks\TaskPreferredTimes;
use App\Services\Tasks\TaskVendorAvailability;
use App\Services\Tasks\TaskWriter;
use App\Services\TaskTitleSuggestions;
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
     * flushing any predecessors chosen before the task existed
     * (TaskCreate::save()'s own $pendingDependencies flush — see
     * availablePredecessorsForNewTask() for choosing them beforehand), then
     * — for a Meet with a start date — the calendar event. Accepts
     * `parent_task_id` (TaskForm's own field, used for the sibling-overlap
     * check). The predecessor/lag/type dependency editor
     * (App\Models\TaskDependency) for an ALREADY-SAVED task is a separate
     * add/remove flow — see dependencies()/storeDependency()/
     * destroyDependency() below.
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

        $this->flushPendingDependencies($task, (array) $request->validated('pending_dependencies'));

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

    /**
     * Mirrors TaskCreate::removeTask() exactly: soft-delete, or — reopening
     * an already-trashed task and deleting it again — a permanent
     * forceDelete (App\Observers\TaskObserver::deleted()/forceDeleted()
     * handle the rest).
     */
    public function destroy(Request $request, int $task): JsonResponse
    {
        $found = $this->visibleTask($task);
        Gate::authorize('delete', $found);

        if ($found->trashed()) {
            $found->forceDelete();
        } else {
            $found->delete();
        }

        return response()->json(['data' => ['id' => $found->id, 'deleted' => true]]);
    }

    /**
     * Mirrors TaskCreate::restoreTask(): a trashed task, same tenant check
     * as every other task route (Task carries no scope of its own).
     */
    public function restore(Request $request, int $task): JsonResponse
    {
        $found = $this->visibleTask($task);
        abort_unless($found->trashed(), 404);

        Gate::authorize('restore', $found);

        $found->restore();

        return response()->json(['data' => (new TaskResource($found))->toArray($request)]);
    }

    /**
     * Mirrors TaskCreate::confirmVendorAvailability()/
     * rejectVendorAvailability()/resetVendorAvailability() — the Vendor
     * Status toggle next to the task's Vendor field — via the shared
     * App\Services\Tasks\TaskVendorAvailability. TaskCreate's own blade
     * only wires confirm (requested/proposed → confirmed) and reset
     * (confirmed → requested) to a button; reject (requested/proposed →
     * rejected) is the same component method, offered here too since a GC
     * admin may need to mark a sub as declined without waiting on their SMS
     * reply.
     */
    public function confirmVendorAvailability(Request $request, int $task, TaskVendorAvailability $service): JsonResponse
    {
        return $this->respondToVendorAvailability($request, $task, fn (Task $found) => $service->confirm($found));
    }

    public function rejectVendorAvailability(Request $request, int $task, TaskVendorAvailability $service): JsonResponse
    {
        return $this->respondToVendorAvailability($request, $task, fn (Task $found) => $service->reject($found));
    }

    public function resetVendorAvailability(Request $request, int $task, TaskVendorAvailability $service): JsonResponse
    {
        return $this->respondToVendorAvailability($request, $task, fn (Task $found) => $service->reset($found));
    }

    /** @param  callable(Task): Task  $transition */
    private function respondToVendorAvailability(Request $request, int $task, callable $transition): JsonResponse
    {
        $found = $this->visibleTask($task);
        Gate::authorize('update', $found);

        try {
            $transition($found);
        } catch (ValidationException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'errors' => $exception->errors()], 422);
        }

        return response()->json(['data' => (new TaskResource($found->refresh()))->toArray($request)]);
    }

    /**
     * Homeowner-submitted scheduling times for a project — mirrors
     * TaskCreate::servicePreferredSlots()/showAwaitingClientAvailabilityCard()/
     * servicePreferredSubmittedAt()/homeownerAvailability() via the shared
     * App\Services\Tasks\TaskPreferredTimes. `applied` is left for the
     * caller to compute against its own in-progress dates/time_settings
     * (there is no saved form state to check here) — same reasoning as
     * the "apply" actions themselves, which stay pure client-side form
     * edits on both the web and the app until Save is pressed.
     *
     * `task` is optional — omitted while creating a task (no row yet, same
     * as TaskCreate's own create-mode); when given, it must already be
     * visible to this tenant.
     */
    public function preferredTimes(Request $request, TaskPreferredTimes $service): JsonResponse
    {
        $project = Project::query()->whereKey($request->integer('project_id'))->first();
        abort_unless($project, 404);

        $taskId = $request->integer('task_id') ?: null;
        $task = $taskId ? Task::visibleTo($taskId) : null;
        abort_if($taskId && ! $task, 403, 'You do not have access to this task.');

        $groupedSlots = $service->groupedSlots($project, $task);

        return response()->json(['data' => [
            'service_preferred_slots' => $groupedSlots,
            'show_awaiting_client_availability_card' => $service->showAwaitingCard($project, $task, $groupedSlots),
            'service_preferred_submitted_at' => $service->submittedAt($project),
            'homeowner_availability' => (string) $request->query('type') === 'Meet'
                ? $service->homeownerAvailability($project)
                : null,
        ]]);
    }

    /**
     * Meeting-participant contacts to pick from — mirrors
     * TaskCreate::availableMeetingContacts()/participantSearchResults()
     * via the shared App\Services\Tasks\MeetingContactsFinder. `project_id`
     * and `vendor_id` are optional (a brand-new Meet may not have either
     * chosen yet); `search` narrows to everyone the company knows, same as
     * typing into the web's autocomplete.
     */
    public function meetingContacts(Request $request, MeetingContactsFinder $finder): JsonResponse
    {
        $projectId = $request->integer('project_id') ?: null;
        $vendorId = $request->integer('vendor_id') ?: null;
        $search = (string) $request->string('search');

        $selected = collect((array) $request->input('selected', []))
            ->map(fn ($email) => (string) $email)
            ->all();

        return response()->json(['data' => $finder->searchResults($projectId, $vendorId, $selected, $search)]);
    }

    /**
     * The Title field's autocomplete — mirrors
     * App\Livewire\Tasks\TaskCreate::titleSuggestions() (really
     * App\Services\TaskTitleSuggestions::titlesForCurrentUser()): every
     * title this tenant has used before, most-used first.
     */
    public function titleSuggestions(Request $request): JsonResponse
    {
        return response()->json(['data' => TaskTitleSuggestions::titlesForCurrentUser()]);
    }

    /**
     * The vendor remembered for a title — mirrors TaskCreate::
     * applyRememberedVendorFromTitle(): picking a title suggestion, or
     * blurring onto an exact (case-insensitive) match, fills the Vendor
     * field when one vendor overwhelmingly did that work before, but only
     * when nothing is selected yet (the caller's job, same as the web —
     * this never overwrites a choice already made).
     */
    public function titleVendorSuggestion(Request $request): JsonResponse
    {
        $title = trim((string) $request->query('title', ''));

        if ($title === '') {
            return response()->json(['data' => null]);
        }

        // Scoped the same way the web's Vendor select is (VendorScope, the
        // model's own global scope) — queried directly rather than through
        // a vendor-list endpoint, so a remembered vendor id never goes
        // stale just because some other index hasn't caught up.
        $selectableVendorIds = Vendor::query()->pluck('id')->map(fn ($id) => (int) $id)->all();

        $vendorId = TaskTitleSuggestions::dominantVendorId($title, $selectableVendorIds);

        if ($vendorId === null) {
            return response()->json(['data' => null]);
        }

        $vendor = Vendor::query()->find($vendorId);

        if (! $vendor) {
            return response()->json(['data' => null]);
        }

        return response()->json(['data' => ['id' => $vendor->id, 'business_name' => $vendor->business_name]]);
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
     * Candidate predecessors for a task that doesn't exist yet — mirrors
     * App\Livewire\Tasks\TaskCreate::availableTasks() in its not-yet-saved
     * branch: scheduled tasks on the chosen project, minus whichever this
     * screen has already picked this session (`exclude[]`, the app's
     * equivalent of the web's $pendingDependencies list it's built from).
     */
    public function availablePredecessorsForNewTask(Request $request): JsonResponse
    {
        $projectId = $request->integer('project_id');
        abort_unless(Project::query()->whereKey($projectId)->exists(), 404);

        $excludeIds = collect((array) $request->input('exclude', []))->map(fn ($id) => (int) $id)->all();

        $tasks = app(TaskDependencyWriter::class)->availableTasks($projectId, null, $excludeIds);

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

    /**
     * Writes the predecessors chosen before $task existed — mirrors
     * TaskCreate::save()'s $pendingDependencies flush. Each goes through
     * TaskDependencyWriter::add() (self/circular/visibility checks); one
     * that fails is skipped rather than failing the whole create, same as
     * the web's own `continue` past a circular one.
     *
     * @param  list<array{predecessor_task_id: int, type: string, lag_days?: ?int}>  $pending
     */
    private function flushPendingDependencies(Task $task, array $pending): void
    {
        if ($pending === []) {
            return;
        }

        $writer = app(TaskDependencyWriter::class);

        foreach ($pending as $dependency) {
            try {
                $writer->add(
                    $task,
                    (int) $dependency['predecessor_task_id'],
                    (string) $dependency['type'],
                    (int) ($dependency['lag_days'] ?? 0),
                );
            } catch (ValidationException $e) {
                continue;
            }
        }
    }
}
