<?php

namespace App\Models;

use App\Models\User;
use App\Traits\Sortable;
use App\Observers\TaskObserver;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Traits\LogsActivity;

#[ObservedBy([TaskObserver::class])]
class Task extends Model
{
    use HasFactory, LogsActivity, SoftDeletes, Sortable;

    protected $fillable = [
        'title', 'project_id', 'start_date', 'end_date', 'order', 'options',
        'type', 'vendor_id', 'user_ids', 'progress', 'notes', 'belongs_to_vendor_id',
        'created_by_user_id', 'created_at', 'updated_at', 'deleted_at', 'parent_task_id',
        'vendor_status', 'vendor_status_token'
    ];

    /**
     * Accessor used by the vendor availability page.
     * Example: "Mon, Jan 13, 2026 @ 7AM"
     */
    public function getDateWithTimeAttribute(): string
    {
        $startDate = $this->start_date;
        $endDate = $this->end_date;

        if (! $startDate) {
            return '';
        }

        $hasMultipleDays = $endDate && ! $startDate->eq($endDate);

        $options = $this->options;
        $timeSettings = is_object($options) ? ($options->time_settings ?? null) : ($options['time_settings'] ?? null);
        $dateKey = $startDate->format('Y-m-d');
        $startTime = null;
        $endTime = null;

        if ($timeSettings) {
            $daySettings = is_object($timeSettings) ? ($timeSettings->$dateKey ?? null) : ($timeSettings[$dateKey] ?? null);
            if ($daySettings) {
                $useTime = is_object($daySettings) ? ($daySettings->use_time ?? false) : ($daySettings['use_time'] ?? false);
                if ($useTime) {
                    $startTime = is_object($daySettings) ? ($daySettings->start_time ?? null) : ($daySettings['start_time'] ?? null);
                    $endTime = is_object($daySettings) ? ($daySettings->end_time ?? null) : ($daySettings['end_time'] ?? null);
                }
            }
        }

        $dateStr = $startDate->format('D, M j, Y');

        if ($startTime && ! $hasMultipleDays) {
            $startFormatted = Carbon::createFromFormat('H:i', $startTime)->format('gA');

            if (is_string($endTime) && $endTime !== '' && $endTime !== $startTime) {
                $endFormatted = Carbon::createFromFormat('H:i', $endTime)->format('gA');
                $dateStr .= " @ {$startFormatted} - {$endFormatted}";
            } else {
                $dateStr .= " @ {$startFormatted}";
            }
        }

        if ($hasMultipleDays) {
            $dateStr .= ' - ' . $endDate->format('D, M j, Y');
        }

        return $dateStr;
    }

    /**
     * Get a formatted arrival time label for a specific date.
     *
     * Returns a range ("8:30 PM - 9 PM") when start and end differ,
     * or a single time ("8 AM") when they're the same.
     * Minutes are omitted when on the hour.
     */
    public function getArrivalTimeLabel(string $date): ?string
    {
        $dayTimeSettings = data_get($this->options, "time_settings.$date");

        return self::formatTimeSettingsLabel(
            is_object($dayTimeSettings) ? (array) $dayTimeSettings : ($dayTimeSettings ?? [])
        );
    }

    /**
     * Whether this task's scheduled time window on the given date is already
     * over (e.g. a "7-7:30AM" arrival checked at 2PM). Tasks without a time
     * set are never considered passed — they run all day.
     */
    public function timeHasPassedOn(string $date, ?string $timezone = null): bool
    {
        $settings = data_get($this->options, "time_settings.$date");
        $settings = is_object($settings) ? (array) $settings : (array) ($settings ?? []);

        if (empty($settings['use_time'])) {
            return false;
        }

        $time = (string) ($settings['end_time'] ?? '');
        if ($time === '') {
            $time = (string) ($settings['start_time'] ?? '');
        }
        if ($time === '') {
            return false;
        }

        try {
            return Carbon::createFromFormat('Y-m-d H:i', "{$date} {$time}", $timezone ?: config('app.timezone'))
                ->isPast();
        } catch (\Exception) {
            return false;
        }
    }

    /**
     * Maps a homeowner-selected time frame to its arrival start time.
     *
     * @var array<string, string>
     */
    private const SERVICE_TIME_FRAME_STARTS = [
        '7-9 AM' => '07:00',
        '9-11 AM' => '09:00',
        '11-1 PM' => '11:00',
        '1-3 PM' => '13:00',
        '3-5 PM' => '15:00',
    ];

    /**
     * Whether the project has homeowner-submitted preferred service times.
     */
    public function projectHasHomeownerPreferredTimes(): bool
    {
        return ! empty(data_get($this->project?->service_availability, 'slots'));
    }

    /**
     * Whether one of the homeowner's preferred service times has been scheduled
     * onto this task (matching date + arrival time frame).
     */
    public function hasScheduledHomeownerPreferredTime(): bool
    {
        $slots = (array) data_get($this->project?->service_availability, 'slots', []);

        if (empty($slots)) {
            return false;
        }

        $selectedDates = (array) data_get($this->options, 'dates', []);

        foreach ($slots as $slot) {
            $date = is_array($slot) ? ($slot['date'] ?? null) : null;
            $time = is_array($slot) ? ($slot['time'] ?? null) : null;

            if ($date === null || $time === null || ! in_array($date, $selectedDates, true)) {
                continue;
            }

            $setting = (array) data_get($this->options, "time_settings.$date", []);
            $frameStart = self::SERVICE_TIME_FRAME_STARTS[$time] ?? null;

            if ($frameStart === null) {
                if (empty($setting['use_time'])) {
                    return true;
                }

                continue;
            }

            if (! empty($setting['use_time']) && ($setting['start_time'] ?? null) === $frameStart) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether this specific task was included in the homeowner's submitted
     * preferred times. When the submission recorded no task ids we can't tell
     * per task, so we assume it is covered.
     */
    public function coveredByHomeownerPreferredTimes(): bool
    {
        $savedTaskIds = collect((array) data_get($this->project?->service_availability, 'task_ids', []))
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->all();

        if ($savedTaskIds === []) {
            return true;
        }

        return in_array((int) $this->id, $savedTaskIds, true);
    }

    /**
     * The homeowner preferred-time indicator state for this task:
     * 'scheduled' when the task already has a date (green), 'schedule' when the
     * homeowner submitted times covering this task but it is still unscheduled
     * (red), 'pending' when the homeowner has not chosen times for this task yet
     * (orange), otherwise null.
     */
    public function preferredTimeIndicator(): ?string
    {
        if (! $this->projectHasHomeownerPreferredTimes()) {
            // Service Call project where the homeowner hasn't submitted any
            // times yet — an unscheduled task is awaiting client availability.
            if (empty($this->start_date) && (int) ($this->project?->latestStatus?->status_code ?? 0) === 8) {
                return 'pending';
            }

            return null;
        }

        if ($this->hasScheduledHomeownerPreferredTime() || ! empty($this->start_date)) {
            return 'scheduled';
        }

        if (! $this->coveredByHomeownerPreferredTimes()) {
            return 'pending';
        }

        return 'schedule';
    }

    /**
     * Request-scoped cache of recent 'updated' activities per task id.
     * Container-bound (not static) so each request — and each test — starts
     * clean. List views prime it in one query; ad-hoc callers fall back to a
     * per-task query whose result is memoized here too.
     */
    protected static function updateActivityCache(): \ArrayObject
    {
        if (! app()->bound('tasks.update-activity-cache')) {
            app()->instance('tasks.update-activity-cache', new \ArrayObject);
        }

        return app('tasks.update-activity-cache');
    }

    /**
     * Fetch recent update activities for many tasks in ONE query. Card lists
     * call getPreviousArrivalTimeLabel() per task — unprimed, that was one
     * activity-log query per card.
     */
    public static function primeUpdateActivities(iterable $taskIds): void
    {
        $cache = self::updateActivityCache();

        $missing = collect($taskIds)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->reject(fn (int $id) => $cache->offsetExists($id))
            ->values();

        if ($missing->isEmpty()) {
            return;
        }

        $grouped = Activity::query()
            ->where('subject_type', self::class)
            ->whereIn('subject_id', $missing)
            ->where('event', 'updated')
            ->latest()
            ->get()
            ->groupBy('subject_id');

        foreach ($missing as $id) {
            $cache[$id] = ($grouped[$id] ?? collect())->take(50)->values();
        }
    }

    /**
     * Hydrate the assigned-users relation for a whole list of tasks with ONE
     * query. Card lists read $task->users per card — unprimed, that is one
     * User::whereIn query per task (Task::getUsersAttribute).
     *
     * @param  \Illuminate\Support\Collection<int, self>  $tasks
     */
    public static function primeAssignedUsers($tasks): void
    {
        $tasks = collect($tasks)->reject(fn (self $task) => $task->relationLoaded('users'));

        $allUserIds = $tasks->pluck('user_ids')->flatten()->filter()->unique()->values();

        $usersById = $allUserIds->isNotEmpty()
            ? User::query()->whereIn('id', $allUserIds)->get()->keyBy('id')
            : collect();

        foreach ($tasks as $task) {
            $task->setRelation('users', collect($task->user_ids ?? [])
                ->map(fn ($userId) => $usersById->get($userId))
                ->filter()
                ->values());
        }
    }

    /**
     * Get the previous arrival time label before the most recent change.
     *
     * Queries the activity log for the most recent time_settings change
     * for the given date and returns the old time if it differs from current.
     */
    public function getPreviousArrivalTimeLabel(string $date): ?string
    {
        $cache = self::updateActivityCache();

        if (! $cache->offsetExists((int) $this->id)) {
            $cache[(int) $this->id] = Activity::query()
                ->where('subject_type', self::class)
                ->where('subject_id', $this->id)
                ->where('event', 'updated')
                ->latest()
                ->limit(50)
                ->get();
        }

        $activities = $cache[(int) $this->id];

        foreach ($activities as $activity) {
            $oldOptions = data_get($activity->properties, 'old.options');
            $newOptions = data_get($activity->properties, 'attributes.options');

            if ($oldOptions === null || $newOptions === null) {
                continue;
            }

            $oldOptions = is_string($oldOptions) ? json_decode($oldOptions, true) : (array) $oldOptions;
            $newOptions = is_string($newOptions) ? json_decode($newOptions, true) : (array) $newOptions;

            $oldTimeSetting = (array) ($oldOptions['time_settings'][$date] ?? []);
            $newTimeSetting = (array) ($newOptions['time_settings'][$date] ?? []);

            if ($oldTimeSetting == $newTimeSetting) {
                continue;
            }

            $oldLabel = self::formatTimeSettingsLabel($oldTimeSetting);

            if ($oldLabel && $oldLabel !== $this->getArrivalTimeLabel($date)) {
                return $oldLabel;
            }

            return null;
        }

        return null;
    }

    /**
     * Format a time_settings array into a human-readable label.
     */
    public static function formatTimeSettingsLabel(array $settings): ?string
    {
        $useTime = (bool) ($settings['use_time'] ?? false);
        $startTime = (string) ($settings['start_time'] ?? '');
        $endTime = (string) ($settings['end_time'] ?? '');

        if (! $useTime || $startTime === '') {
            return null;
        }

        try {
            $formatTime = static fn(string $t): string => (($c = Carbon::createFromFormat('H:i', $t)) && $c->minute === 0)
                ? $c->format('gA')
                : $c->format('g:iA');

            $formatTimeShort = static fn(string $t): string => (($c = Carbon::createFromFormat('H:i', $t)) && $c->minute === 0)
                ? $c->format('g')
                : $c->format('g:i');

            $startLabel = $formatTime($startTime);

            if ($endTime !== '' && $endTime !== $startTime) {
                $startCarbon = Carbon::createFromFormat('H:i', $startTime);
                $endCarbon = Carbon::createFromFormat('H:i', $endTime);

                if ($startCarbon->format('A') === $endCarbon->format('A')) {
                    return $formatTimeShort($startTime) . '-' . $formatTime($endTime);
                }

                return $startLabel . '-' . $formatTime($endTime);
            }

            return $startLabel;
        } catch (\Exception) {
            return null;
        }
    }

    public const VENDOR_STATUS_REQUESTED = 'requested';
    public const VENDOR_STATUS_CONFIRMED = 'confirmed';
    public const VENDOR_STATUS_REJECTED = 'rejected';
    public const VENDOR_STATUS_PROPOSED = 'proposed';

    public const VENDOR_STATUS_UI = [
        self::VENDOR_STATUS_REQUESTED => [
            'label' => 'Requested',
            'flux' => 'yellow',
            'icon' => 'clock',
        ],
        self::VENDOR_STATUS_CONFIRMED => [
            'label' => 'Confirmed',
            'flux' => 'green',
            'icon' => 'check-circle',
        ],
        self::VENDOR_STATUS_REJECTED => [
            'label' => 'Rejected',
            'flux' => 'red',
            'icon' => 'x-circle',
        ],
        self::VENDOR_STATUS_PROPOSED => [
            'label' => 'Proposed',
            'flux' => 'indigo',
            'icon' => 'calendar',
        ],
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'options' => 'object',
            'user_ids' => 'array',
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'title',
                'start_date',
                'end_date',
                'type',
                'vendor_id',
                'user_ids',
                'progress',
                'options',
                'order',
                'parent_task_id',
                'vendor_status',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('tasks');
    }

    /**
     * Get the vendor status UI config.
     *
     * @return Attribute<array{label:string,flux:string,icon:string}|null, never>
     */
    public function vendorStatusUi(): Attribute
    {
        return Attribute::make(
            get: fn () => self::VENDOR_STATUS_UI[$this->vendor_status] ?? null,
        );
    }

    public const TYPE_UI = [
        'Task' => [
            'flux' => 'sky',
            'text' => 'text-sky-600 dark:text-sky-400',
            'border' => 'border-sky-500',
            'bg' => 'bg-sky-500',
            'bg_strong' => 'bg-sky-600',
            'hover_bg_strong' => 'hover:bg-sky-600',
        ],
        'Milestone' => [
            'flux' => 'indigo',
            'text' => 'text-indigo-600 dark:text-indigo-400',
            'border' => 'border-indigo-500',
            'bg' => 'bg-indigo-500',
            'bg_strong' => 'bg-indigo-600',
            'hover_bg_strong' => 'hover:bg-indigo-600',
        ],
        'Meet' => [
            'flux' => 'orange',
            'text' => 'text-orange-600 dark:text-orange-400',
            'border' => 'border-orange-500',
            'bg' => 'bg-orange-500',
            'bg_strong' => 'bg-orange-600',
            'hover_bg_strong' => 'hover:bg-orange-600',
        ],
        'Reminder' => [
            'flux' => 'rose',
            'text' => 'text-rose-600 dark:text-rose-400',
            'border' => 'border-rose-500',
            'bg' => 'bg-rose-500',
            'bg_strong' => 'bg-rose-600',
            'hover_bg_strong' => 'hover:bg-rose-600',
        ],
    ];

    /**
     * Accessor: full UI mapping for the current task type.
     *
     * @return Attribute<array{flux:string,text:string,border:string,bg:string,bg_strong:string,hover_bg_strong:string}, never>
     */
    public function typeUi(): Attribute
    {
        return Attribute::make(
            get: fn () => self::TYPE_UI[$this->type ?? 'Task'] ?? self::TYPE_UI['Task'],
        );
    }

    protected function scopeSortable($query, $task)
    {
        return $task->project->tasks();
    }

    public function wouldOverlapWithSiblings($startDate, $endDate)
    {
        $tasksToCheck = collect();

        // Get siblings and parent/children to check
        if ($this->parent_task_id) {
            // This is a child task - check other children of the same parent.
            // The parent itself is intentionally excluded: a child sitting
            // inside its parent's range is expected, not a conflict.
            $siblings = Task::where('project_id', $this->project_id)
                           ->where('id', '!=', $this->id ?? 0)
                           ->where('parent_task_id', $this->parent_task_id)
                           ->whereNotNull('start_date')
                           ->whereNotNull('end_date')
                           ->get();
            $tasksToCheck = $tasksToCheck->merge($siblings);

        } elseif ($this->exists && $this->children()->exists()) {
            // Parent tasks are not blocked by their own children's ranges; a
            // parent encompassing its children is the normal case.
            return false;

        } else {
            return false;
        }

        // Ensure we're working with strings for consistent comparison
        $newStartDate = is_string($startDate) ? $startDate : $startDate->format('Y-m-d');
        $newEndDate = is_string($endDate) ? $endDate : $endDate->format('Y-m-d');

        // Make sure start date is not after end date
        if ($newStartDate > $newEndDate) {
            [$newStartDate, $newEndDate] = [$newEndDate, $newStartDate];
        }

        foreach ($tasksToCheck as $taskToCheck) {
            $checkStart = $taskToCheck->start_date->format('Y-m-d');
            $checkEnd = $taskToCheck->end_date->format('Y-m-d');

            // Two date ranges overlap if:
            // (newStart <= checkEnd) AND (newEnd >= checkStart)
            $overlaps = ($newStartDate <= $checkEnd && $newEndDate >= $checkStart);

            if ($overlaps) {
                return true;
            }
        }

        return false;
    }

    // Basic relationships
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * The company (vendor) that owns this task.
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'belongs_to_vendor_id');
    }

    // Parent-Child relationships
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'parent_task_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Task::class, 'parent_task_id');
    }

    // Simple helper methods
    public function siblings(): HasMany
    {
        return $this->hasMany(Task::class, 'parent_task_id', 'parent_task_id')
                   ->where('id', '!=', $this->id);
    }

    protected function vendorId(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => empty($value) ? null : $value,
        );
    }

    // Dependencies where this task is the predecessor
    public function successorDependencies(): HasMany
    {
        return $this->hasMany(TaskDependency::class, 'predecessor_task_id');
    }

    // Dependencies where this task is the successor
    public function predecessorDependencies(): HasMany
    {
        return $this->hasMany(TaskDependency::class, 'successor_task_id');
    }

    // Tasks that must finish before this task can start
    public function predecessorTasks(): BelongsToMany
    {
        return $this->belongsToMany(Task::class, 'task_dependencies', 'successor_task_id', 'predecessor_task_id')
                    ->withPivot('type', 'lag_days', 'id')
                    ->withTimestamps();
    }

    // Tasks that depend on this task
    public function successorTasks(): BelongsToMany
    {
        return $this->belongsToMany(Task::class, 'task_dependencies', 'predecessor_task_id', 'successor_task_id')
                    ->withPivot('type', 'lag_days', 'id')
                    ->withTimestamps();
    }

    // Check if this task can start based on its dependencies
    // public function canStart(): bool
    // {
    //     foreach ($this->predecessorTasks as $predecessor) {
    //         $dependencyType = $predecessor->pivot->type;

    //         switch ($dependencyType) {
    //             case 'finish_to_start':
    //                 if (!$predecessor->end_date || $predecessor->progress < 100) {
    //                     return false;
    //                 }
    //                 break;
    //             case 'start_to_start':
    //                 if (!$predecessor->start_date) {
    //                     return false;
    //                 }
    //                 break;
    //             // Add other dependency type checks as needed
    //         }
    //     }

    //     return true;
    // }

    // Calculate the earliest possible start date based on dependencies
    // public function getEarliestStartDate(): ?Carbon
    // {
    //     $earliestDate = null;

    //     foreach ($this->predecessorTasks as $predecessor) {
    //         $dependencyType = $predecessor->pivot->type;
    //         $lagDays = $predecessor->pivot->lag_days;

    //         $calculatedDate = null;

    //         switch ($dependencyType) {
    //             case 'finish_to_start':
    //                 if ($predecessor->end_date) {
    //                     $calculatedDate = $predecessor->end_date->copy()->addDays($lagDays + 1);
    //                 }
    //                 break;
    //             case 'start_to_start':
    //                 if ($predecessor->start_date) {
    //                     $calculatedDate = $predecessor->start_date->copy()->addDays($lagDays);
    //                 }
    //                 break;
    //             // Add other dependency types
    //         }

    //         if ($calculatedDate && (!$earliestDate || $calculatedDate->gt($earliestDate))) {
    //             $earliestDate = $calculatedDate;
    //         }
    //     }

    //     return $earliestDate;
    // }

    /**
     * Get users assigned to this task using the JSON user_ids column
     * This is an accessor that returns a collection directly
     */
    public function getUsersAttribute()
    {
        // If we've already loaded the users, return that
        if ($this->relationLoaded('users')) {
            return $this->getRelation('users');
        }
        
        // Otherwise query them directly
        $users = User::whereIn('id', $this->user_ids ?? [])->get();
        
        // Store for later access
        $this->setRelation('users', $users);
        
        return $users;
    }

    /**
     * Get total number of dependencies (both predecessor and successor)
     */
    public function getTotalDependenciesCountAttribute()
    {
        // If counts were loaded via loadCount()
        if (isset($this->predecessor_dependencies_count) && isset($this->successor_dependencies_count)) {
            return $this->predecessor_dependencies_count + $this->successor_dependencies_count;
        }
        
        // If relationships were already loaded
        if ($this->relationLoaded('predecessorDependencies') && $this->relationLoaded('successorDependencies')) {
            return $this->predecessorDependencies->count() + $this->successorDependencies->count();
        }
        
        // Fallback - least efficient, makes separate queries
        return $this->predecessorDependencies()->count() + $this->successorDependencies()->count();
    }

    /**
     * Per-day task-card counts for the loading skeleton, one entry per date
     * block the loaded card paints. Mirrors groupedTasks(): the same 8-day
     * window, the same options.dates / start_date grouping, and the same
     * padding of empty days — so the skeleton is the same height as the card
     * that replaces it. Two light columns, a handful of rows.
     *
     * @return array{past: bool, days: array<int, array<int, bool>>}
     */
    public static function skeletonDayCounts(\Illuminate\Database\Eloquent\Builder $tasks): array
    {
        $today = browser_today();
        $cutoffStr = $today->copy()->subDay()->format('Y-m-d');
        $windowEndStr = $today->copy()->addDays(6)->format('Y-m-d');

        $rows = $tasks
            ->whereNotNull('start_date')
            ->whereNotNull('end_date')
            ->whereDate('start_date', '<=', $windowEndStr)
            ->whereDate('end_date', '>=', $cutoffStr)
            ->get(['id', 'start_date', 'options', 'user_ids', 'vendor_id']);

        // Per day, one entry per task card: true when that card carries the
        // avatar/vendor row (16px taller), so the skeleton matches both shapes.
        $byDate = [];
        foreach ($rows as $task) {
            $dates = (array) data_get($task->options, 'dates', []);
            $dates = $dates ?: [optional($task->start_date)->format('Y-m-d')];
            $hasPeople = filled(array_filter((array) $task->user_ids)) || filled($task->vendor_id);

            foreach (array_filter($dates) as $dateStr) {
                if ($dateStr >= $cutoffStr && $dateStr <= $windowEndStr) {
                    $byDate[$dateStr][] = $hasPeople;
                }
            }
        }

        // Yesterday is not a day block: the loaded card tucks past tasks into a
        // collapsed accordion, so it contributes one slim row (represented by
        // the 'past' key) instead.
        $blocks = ['past' => ! empty($byDate[$cutoffStr]), 'days' => []];

        for ($i = 0; $i < 7; $i++) {
            $dateStr = $today->copy()->addDays($i)->format('Y-m-d');
            $blocks['days'][] = $byDate[$dateStr] ?? [];
        }

        return $blocks;
    }
}
