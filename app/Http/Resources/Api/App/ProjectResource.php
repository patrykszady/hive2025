<?php

namespace App\Http\Resources\Api\App;

use App\Models\ProjectStatus;
use App\Models\ProjectTimelapseFrame;
use App\Models\Task;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

/**
 * A project as the app's Projects tab shows it. With withDetail() it adds
 * everything the detail screen needs beyond the list row — see
 * ProjectController::show().
 *
 * `upcoming_task` is read from a transient `upcoming_task_row` attribute the
 * controller sets in a single batched query across the whole page
 * (AppProjectDirectory::attachUpcomingTaskSummaries()) rather than queried
 * per row here, the same pattern ThreadController uses for unread counts.
 *
 * @mixin \App\Models\Project
 */
class ProjectResource extends JsonResource
{
    protected bool $detail = false;

    protected ?int $vendorId = null;

    /** The viewing vendor — needed to read the right vendor's status off a
     * project that may carry history for more than one collaborating vendor. */
    public function forVendor(int $vendorId): static
    {
        $this->vendorId = $vendorId;

        return $this;
    }

    public function withDetail(): static
    {
        $this->detail = true;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $vendorId = $this->vendorId ?? (int) $request->user()?->vendor?->id;
        $status = $this->resource->latestVendorStatus($vendorId);
        $client = $this->resource->client;

        $clientPayload = $client ? ['id' => $client->id, 'name' => $client->name] : null;

        if ($client && $this->detail) {
            $clientPayload['people'] = $client->users->map(fn (User $user) => [
                'id' => $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'cell_phone' => $user->cell_phone,
                'email' => $user->email,
            ])->values()->all();
        }

        $row = [
            'id' => $this->id,
            'name' => $this->project_name,
            'address' => [
                'line1' => $this->address,
                'line2' => $this->address_2,
                'city' => $this->city,
                'state' => $this->state,
                'zip' => $this->zip_code,
                'short' => $this->short_address,
            ],
            'client' => $clientPayload,
            'status' => $status ? $this->statusArray($status) : null,
            'status_since' => $status?->start_date?->toDateString(),
            'last_activity_at' => $this->lastActivityAt($status)->toIso8601String(),
            'upcoming_task' => $this->resource->upcoming_task_row ?? null,
            'web_path' => '/projects/'.$this->id,
        ];

        if (! $this->detail) {
            return $row;
        }

        return $row + [
            'note' => $this->note,
            'service_availability' => $this->service_availability,
            // Project has no areas relation or table at all (checked the
            // model and the schema), so this is always empty rather than
            // fabricated; kept as a plain string[] so the app doesn't need
            // a special case if areas are added later.
            'areas' => [],
            'vendors' => $this->resource->vendors->map(fn (Vendor $vendor) => [
                'id' => $vendor->id,
                'business_name' => $vendor->business_name,
                'business_phone' => $vendor->business_phone,
            ])->values()->all(),
            'upcoming_tasks' => $this->upcomingTasks(),
            'status_history' => $this->statusHistory(),
            'counts' => [
                'estimates' => $this->resource->estimates()->count(),
                'expenses' => $this->resource->expenses()->count(),
                'payments' => $this->resource->payments()->count(),
                'photos' => ProjectTimelapseFrame::query()
                    ->whereHas('timelapse', fn ($q) => $q->where('project_id', $this->id))
                    ->count(),
            ],
            'latest_photos' => $this->latestPhotos(),
        ];
    }

    /**
     * @return array{code: int, title: string, color: string}
     */
    private function statusArray(ProjectStatus $status): array
    {
        return [
            'code' => $status->status_code,
            'title' => $status->title,
            'color' => $status->badge_color,
        ];
    }

    /**
     * The latest of the status date and the project row's own last edit —
     * the project has no single "touched" column, so this is the closest
     * honest signal available from existing data.
     */
    private function lastActivityAt(?ProjectStatus $status): \Illuminate\Support\Carbon
    {
        $candidates = array_filter([$this->resource->updated_at, $status?->start_date]);

        return collect($candidates)->sort()->last() ?? now();
    }

    /**
     * The next 10 tasks, newest-ending-soonest-first, as the detail screen's
     * task list needs them.
     *
     * @return list<array{id: int, title: string, type: string, date: string, start_time: ?string, end_time: ?string, users: list<string>, vendor: array{id: int, business_name: string}|null}>
     */
    private function upcomingTasks(): array
    {
        $tasks = Task::query()
            ->where('project_id', $this->id)
            ->whereNotNull('start_date')
            ->whereNotNull('end_date')
            ->where('end_date', '>=', today())
            ->with('vendor:id,business_name')
            ->orderBy('start_date')
            ->orderBy('end_date')
            ->limit(10)
            ->get();

        $userIds = $tasks->pluck('user_ids')->flatten()->filter()->unique()->values();
        $usersById = $userIds->isNotEmpty()
            ? User::query()->whereIn('id', $userIds)->get(['id', 'first_name', 'last_name', 'nickname'])->keyBy('id')
            : collect();

        return $tasks->map(function (Task $task) use ($usersById) {
            $dateKey = $task->start_date->format('Y-m-d');
            $daySettings = data_get($task->options, "time_settings.{$dateKey}", null);
            $usesTime = (bool) data_get($daySettings, 'use_time', false);

            return [
                'id' => $task->id,
                'title' => $task->title,
                'type' => $task->type,
                'date' => $dateKey,
                'start_time' => $usesTime ? (data_get($daySettings, 'start_time') ?: null) : null,
                'end_time' => $usesTime ? (data_get($daySettings, 'end_time') ?: null) : null,
                'users' => collect($task->user_ids ?? [])
                    ->map(fn ($id) => $usersById->get($id))
                    ->filter()
                    ->map(fn (User $user) => trim((string) ($user->nickname ?: $user->first_name).' '.$user->last_name))
                    ->values()
                    ->all(),
                'vendor' => $task->vendor ? ['id' => $task->vendor->id, 'business_name' => $task->vendor->business_name] : null,
            ];
        })->values()->all();
    }

    /**
     * The latest 10 status changes for the viewing vendor — ProjectStatus's
     * own global scope already limits this to that vendor's rows.
     *
     * @return list<array{code: int, title: string, start_date: string}>
     */
    private function statusHistory(): array
    {
        return $this->resource->statuses()
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->map(fn (ProjectStatus $status) => [
                'code' => $status->status_code,
                'title' => $status->title,
                'start_date' => $status->start_date?->toDateString(),
            ])
            ->values()
            ->all();
    }

    /**
     * Up to 6 of the newest timelapse frames, with session-less signed URLs
     * (see TimelapseController::frameSigned()) so the app can show them
     * without a web session or cookie.
     *
     * @return list<array{id: int, url: string, taken_at: ?string}>
     */
    private function latestPhotos(): array
    {
        return ProjectTimelapseFrame::query()
            ->whereHas('timelapse', fn ($q) => $q->where('project_id', $this->id))
            ->orderByDesc('id')
            ->limit(6)
            ->get()
            ->map(fn (ProjectTimelapseFrame $frame) => [
                'id' => $frame->id,
                'url' => URL::temporarySignedRoute(
                    'projects.timelapse.frame.signed',
                    now()->addHours(24),
                    ['frame' => $frame->id, 'thumb' => 1]
                ),
                'taken_at' => $frame->shot_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }
}
