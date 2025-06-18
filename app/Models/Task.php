<?php

namespace App\Models;

use App\Models\Task;
use App\Models\Traits\Sortable;
use App\Observers\TaskObserver;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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

            \Log::info('Checking task ' . $taskToCheck->id . ': ' . $checkStart . ' to ' . $checkEnd);

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

    public function getUsersAttribute()
    {
        return User::whereIn('id', $this->user_ids ?? [])->get();
    }

    protected function vendorId(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => empty($value) ? null : $value,
        );
    }
}
