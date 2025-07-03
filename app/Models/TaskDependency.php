<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

class TaskDependency extends Model
{
    protected $fillable = [
        'predecessor_task_id',
        'successor_task_id',
        'type',
        'lag_days',
    ];

    protected $casts = [
        'lag_days' => 'integer',
    ];

    // Relationships
    public function predecessor(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'predecessor_task_id');
    }

    public function successor(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'successor_task_id');
    }

    // Validation method to prevent circular dependencies
    public static function wouldCreateCircularDependency($predecessorId, $successorId): bool
    {
        return static::hasPath($successorId, $predecessorId);
    }

    /**
     * Check if there's a path from one task to another through dependencies
     */
    private static function hasPath($fromTaskId, $toTaskId, $visited = []): bool
    {
        // If we've reached the target, we found a path
        if ($fromTaskId === $toTaskId) {
            return true;
        }

        // If we've already visited this task, avoid infinite loops
        if (in_array($fromTaskId, $visited)) {
            return false;
        }

        // Mark this task as visited
        $visited[] = $fromTaskId;

        // Get all tasks that depend on the current task (successors)
        $dependencies = static::where('predecessor_task_id', $fromTaskId)->get();

        // Check each successor to see if there's a path to the target
        foreach ($dependencies as $dependency) {
            if (static::hasPath($dependency->successor_task_id, $toTaskId, $visited)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get all dependencies for a project (useful for Gantt chart visualization)
     */
    public static function getProjectDependencies($projectId)
    {
        return static::whereHas('predecessor', function ($query) use ($projectId) {
            $query->where('project_id', $projectId);
        })->with(['predecessor', 'successor'])->get();
    }

    /**
     * Scope to get dependencies by type
     */
    public function scopeByType($query, $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Get human-readable dependency type
     */
    public function getTypeDisplayAttribute(): string
    {
        return match ($this->type) {
            'finish_to_start' => 'Finish to Start',
            'start_to_start' => 'Start to Start',
            'finish_to_finish' => 'Finish to Finish',
            'start_to_finish' => 'Start to Finish',
            default => ucfirst(str_replace('_', ' ', $this->type))
        };
    }

    /**
     * Get formatted lag display
     */
    public function getLagDisplayAttribute(): string
    {
        if ($this->lag_days == 0) {
            return 'No lag';
        }

        $sign = $this->lag_days > 0 ? '+' : '';
        return $sign . $this->lag_days . ' day' . ($this->lag_days != 1 ? 's' : '');
    }

    /**
     * Check if this dependency blocks the successor from starting
     */
    public function isBlocking()
    {
        // This should return true only for dependencies that are actually blocking
        // For example, when a successor task starts before its predecessor finishes

        if ($this->type === 'finish_to_start') {
            // Check if successor starts before predecessor finishes
            $predecessorEnd = Carbon::parse($this->predecessor->end_date);
            $successorStart = Carbon::parse($this->successor->start_date);

            // Add lag days to predecessor end
            $expectedSuccessorStart = $predecessorEnd->copy()->addDays($this->lag_days + 1);

            // It's blocking if successor starts before it should
            return $successorStart->isBefore($expectedSuccessorStart);
        }

        // Add other dependency type checks here if needed
        return false;
    }
}
