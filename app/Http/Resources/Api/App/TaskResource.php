<?php

namespace App\Http\Resources\Api\App;

use App\Models\Task;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A task exactly as the Hive Contractors app's task screens need it: every
 * field App\Livewire\Forms\TaskForm::setTask() loads for editing, plus a few
 * display-only extras (`is_meet`, `progress`) the planner cards also show.
 *
 * @mixin \App\Models\Task
 */
class TaskResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $dates = collect($this->options->dates ?? [])->map(fn ($date) => (string) $date)->values()->all();

        return [
            'id' => $this->id,
            'title' => $this->title,
            'type' => $this->type,
            'is_meet' => $this->type === 'Meet',
            // Always named: the app's task screen shows the project in its
            // Project field, and show/update never eager-load it.
            'project' => [
                'id' => $this->project_id,
                'name' => $this->resource->project?->project_name,
            ],
            'vendor' => $this->resource->vendor ? [
                'id' => $this->resource->vendor->id,
                'business_name' => $this->resource->vendor->business_name,
            ] : null,
            'user_ids' => collect($this->user_ids ?? [])->map(fn ($id) => (int) $id)->values()->all(),
            'users' => collect($this->user_ids ?? [])
                ->map(fn ($id) => User::query()->withoutGlobalScopes()->find($id))
                ->filter()
                ->map(fn (User $user) => ['id' => $user->id, 'name' => trim((string) ($user->nickname ?: $user->first_name).' '.$user->last_name)])
                ->values()
                ->all(),
            'dates' => $dates,
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'time_settings' => (array) ($this->options->time_settings ?? []),
            // Normalized (every item carries a stable uid, even a legacy
            // row saved before uids existed) — the app sends this same list
            // back as `checklist_original` on save, so
            // App\Services\Tasks\TaskWriter::update()'s three-way merge
            // (Task::mergeChecklist()) can tell an untouched box from one
            // this screen actually changed.
            'checklist' => Task::normalizeChecklist($this->options->checklist ?? []),
            'notes' => $this->notes,
            'progress' => $this->progress !== null ? (int) $this->progress : null,
            'order' => $this->order,
            'parent_task_id' => $this->parent_task_id,
            'meeting_location_type' => $this->options->meeting_location_type ?? null,
            'meeting_participants' => (array) ($this->options->meeting_participants ?? []),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
