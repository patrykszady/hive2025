<?php

namespace App\Models;

use App\Models\Task;
use App\Models\User;
use App\Models\Traits\Sortable;
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

#[ObservedBy([TaskObserver::class])]
class Task extends Model
{
    use HasFactory, SoftDeletes, Sortable;

    protected $fillable = [
        'title', 'project_id', 'start_date', 'end_date', 'order', 'options',
        'type', 'vendor_id', 'user_ids', 'progress', 'notes', 'belongs_to_vendor_id',
        'created_by_user_id', 'created_at', 'updated_at', 'deleted_at', 'parent_task_id'
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

    protected function scopeSortable($query, $task)
    {
        return $task->project->tasks();
    }

    public function wouldOverlapWithSiblings($startDate, $endDate)
    {
        $tasksToCheck = collect();

        // Get siblings and parent/children to check
        if ($this->parent_task_id) {
            // This is a child task - check parent and other children
            $parent = Task::find($this->parent_task_id);
            if ($parent && $parent->start_date && $parent->end_date) {
                $tasksToCheck->push($parent);
            }

            // Also check other children of the same parent
            $siblings = Task::where('project_id', $this->project_id)
                           ->where('id', '!=', $this->id ?? 0)
                           ->where('parent_task_id', $this->parent_task_id)
                           ->whereNotNull('start_date')
                           ->whereNotNull('end_date')
                           ->get();
            $tasksToCheck = $tasksToCheck->merge($siblings);

        } elseif ($this->exists && $this->children()->exists()) {
            // This is a parent task - check its children
            $children = Task::where('project_id', $this->project_id)
                           ->where('id', '!=', $this->id ?? 0)
                           ->where('parent_task_id', $this->id)
                           ->whereNotNull('start_date')
                           ->whereNotNull('end_date')
                           ->get();
            $tasksToCheck = $tasksToCheck->merge($children);

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
