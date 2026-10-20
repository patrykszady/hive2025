<?php

namespace App\Services\Tasks;

use App\Models\Project;
use App\Models\Task;
use Illuminate\Validation\ValidationException;

/**
 * Creates and updates a Task exactly like App\Livewire\Forms\TaskForm's
 * store()/update() — the scoped-field checks, the duplicate-submit guard,
 * the sibling-overlap check, and the options (dates/time_settings/meeting)
 * assembly — so the web dashboard and the Hive Contractors app's task
 * screens write identical rows and trigger the same TaskObserver side
 * effects (realtime/vendor/service-call notifications — Task is model-event
 * driven, so nothing here dispatches those directly).
 *
 * Checklist editing is included only for a caller that supplies BOTH
 * `checklist` and `checklist_original` (the web's three-way
 * mergeChecklist() against the freshest DB row, so a concurrent card-side
 * tick isn't clobbered); a caller that omits both — every app screen today,
 * since no app screen edits a checklist yet — leaves the stored checklist
 * byte-for-byte untouched rather than silently wiping it.
 *
 * `progress` is never written here: no web form field writes it either
 * (see Task::$fillable vs. what TaskForm::store()/update() actually
 * assign) — there is nothing to mirror.
 */
class TaskWriter
{
    /**
     * @param  array{title: string, project_id: int, dates?: list<string>, time_settings?: array<string, array{use_time?: bool, start_time?: ?string, end_time?: ?string}>, vendor_id?: ?int, user_ids?: list<int>, type?: string, notes?: ?string, meeting_location_type?: ?string, meeting_participants?: list<string>, parent_task_id?: ?int, order?: ?int, checklist?: list<array>}  $data
     */
    public function store(array $data): Task
    {
        $this->assertScopedFieldsAreValid($data);

        $type = $data['type'] ?? 'Task';
        $data = $this->normalizeMeetDates($data, $type);

        [$startDate, $endDate] = $this->dateRange($data['dates'] ?? []);

        if ($startDate && $endDate && ! empty($data['parent_task_id'])) {
            $tempTask = new Task([
                'id' => 0,
                'project_id' => $data['project_id'],
                'parent_task_id' => $data['parent_task_id'],
            ]);

            if ($tempTask->wouldOverlapWithSiblings($startDate, $endDate)) {
                throw ValidationException::withMessages(['dates' => 'This task would overlap with a sibling task.']);
            }
        }

        // Prevent duplicate creation (double-tap / double-submit) — same
        // window as TaskForm::store().
        $recentDuplicate = Task::withoutGlobalScopes()
            ->where('project_id', $data['project_id'])
            ->where('title', $data['title'])
            ->where('start_date', $startDate)
            ->where('created_at', '>=', now()->subSeconds(10))
            ->first();

        if ($recentDuplicate) {
            return $recentDuplicate;
        }

        return Task::create([
            'start_date' => $startDate,
            'end_date' => $endDate,
            'project_id' => $data['project_id'],
            'vendor_id' => $data['vendor_id'] ?? null,
            'type' => $type,
            'user_ids' => $this->normalizedUserIds($data['user_ids'] ?? []),
            'title' => $data['title'],
            'notes' => $data['notes'] ?? null,
            'order' => $data['order'] ?? 0,
            // store() never merges — a brand new task has nothing to merge
            // against, same as TaskForm::store().
            'options' => $this->buildOptions($data, $type, $data['checklist'] ?? []),
            'parent_task_id' => $data['parent_task_id'] ?? null,
        ]);
    }

    /**
     * @param  array{title: string, project_id: int, dates?: list<string>, time_settings?: array<string, array{use_time?: bool, start_time?: ?string, end_time?: ?string}>, vendor_id?: ?int, user_ids?: list<int>, type?: string, notes?: ?string, meeting_location_type?: ?string, meeting_participants?: list<string>, parent_task_id?: ?int, order?: ?int, checklist?: list<array>, checklist_original?: list<array>}  $data
     */
    public function update(Task $task, array $data): Task
    {
        $this->assertScopedFieldsAreValid($data);

        $type = $data['type'] ?? $task->type;
        $data = $this->normalizeMeetDates($data, $type);

        [$startDate, $endDate] = $this->dateRange($data['dates'] ?? []);

        if ($startDate && $endDate && $task->wouldOverlapWithSiblings($startDate, $endDate)) {
            throw ValidationException::withMessages(['dates' => 'This task would overlap with a sibling task.']);
        }

        $checklist = array_key_exists('checklist', $data) && array_key_exists('checklist_original', $data)
            ? Task::mergeChecklist(
                data_get($task->fresh()?->options, 'checklist', []),
                $data['checklist_original'],
                $data['checklist'],
            )
            : (array) data_get($task->options, 'checklist', []);

        $task->update([
            'start_date' => $startDate,
            'end_date' => $endDate,
            'project_id' => $data['project_id'],
            'vendor_id' => $data['vendor_id'] ?? null,
            'type' => $type,
            'user_ids' => $this->normalizedUserIds($data['user_ids'] ?? []),
            'title' => $data['title'],
            'notes' => $data['notes'] ?? null,
            'order' => $data['order'] ?? $task->order ?? 0,
            'options' => $this->buildOptions($data, $type, $checklist),
            'parent_task_id' => $data['parent_task_id'] ?? null,
        ]);

        return $task;
    }

    /**
     * @param  array{dates?: list<string>, time_settings?: array<string, array{use_time?: bool, start_time?: ?string, end_time?: ?string}>, meeting_location_type?: ?string, meeting_participants?: list<string>}  $data
     */
    private function buildOptions(array $data, string $type, array $checklist): array
    {
        return [
            'dates' => $data['dates'] ?? [],
            'checklist' => $checklist,
            'time_settings' => $data['time_settings'] ?? [],
            'meeting_location_type' => $type === 'Meet' ? ($data['meeting_location_type'] ?? 'in_person') : null,
            'meeting_participants' => $type === 'Meet' ? array_values(array_filter($data['meeting_participants'] ?? [])) : [],
        ];
    }

    /**
     * A Meet is one appointment on one day — mirrors
     * App\Livewire\Tasks\TaskCreate::updatedFormDates()/updatedFormType():
     * a task saved as Meet with several dates keeps only the last one, and
     * `time_settings` entries for every other date are dropped with it.
     * The web enforces this interactively as dates/type change; this is the
     * write-time backstop so any caller — including the app, which has no
     * such live wiring — can't persist a multi-day Meet.
     *
     * @param  array{dates?: list<string>, time_settings?: array<string, array{use_time?: bool, start_time?: ?string, end_time?: ?string}>}  $data
     * @return array{dates?: list<string>, time_settings?: array<string, array{use_time?: bool, start_time?: ?string, end_time?: ?string}>}
     */
    private function normalizeMeetDates(array $data, string $type): array
    {
        $dates = $data['dates'] ?? [];

        if ($type !== 'Meet' || count($dates) <= 1) {
            return $data;
        }

        sort($dates);
        $keep = end($dates);

        $data['dates'] = [$keep];
        $data['time_settings'] = array_intersect_key(
            (array) ($data['time_settings'] ?? []),
            [$keep => true],
        );

        return $data;
    }

    /** @return array{0: ?string, 1: ?string} */
    private function dateRange(array $dates): array
    {
        if ($dates === []) {
            return [null, null];
        }

        sort($dates);

        return [$dates[0], end($dates)];
    }

    /** @return list<string> */
    private function normalizedUserIds(array $userIds): array
    {
        return collect($userIds)->map(fn ($id) => (string) $id)->values()->all();
    }

    /**
     * project_id, parent_task_id and user_ids are client-chosen — check them
     * against this tenant rather than trusting whatever ids arrive. Mirrors
     * TaskForm::assertScopedFieldsAreValid(): project_id exists, a
     * parent_task_id resolves and shares the same project, and every
     * user_id belongs to the acting user's own vendor.
     */
    private function assertScopedFieldsAreValid(array $data): void
    {
        if (! Project::query()->whereKey($data['project_id'])->exists()) {
            throw ValidationException::withMessages(['project_id' => 'The selected project is invalid.']);
        }

        if (! empty($data['parent_task_id'])) {
            $parent = Task::visibleTo((int) $data['parent_task_id']);

            if (! $parent || (int) $parent->project_id !== (int) $data['project_id']) {
                throw ValidationException::withMessages(['parent_task_id' => 'The selected parent task is invalid.']);
            }
        }

        if (! empty($data['user_ids'])) {
            $vendor = auth()->user()?->vendor;
            $employedUserIds = $vendor ? $vendor->users()->pluck('users.id')->all() : [];
            $requestedUserIds = array_map('intval', $data['user_ids']);

            if (array_diff($requestedUserIds, array_map('intval', $employedUserIds)) !== []) {
                throw ValidationException::withMessages(['user_ids' => 'One or more selected team members are invalid.']);
            }
        }
    }
}
