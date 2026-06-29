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
     * Get the previous arrival time label before the most recent change.
     *
     * Queries the activity log for the most recent time_settings change
     * for the given date and returns the old time if it differs from current.
     */
    public function getPreviousArrivalTimeLabel(string $date): ?string
    {
        $activities = Activity::query()
            ->where('subject_type', self::class)
            ->where('subject_id', $this->id)
            ->where('event', 'updated')
            ->latest()
            ->limit(50)
            ->get();

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
}
