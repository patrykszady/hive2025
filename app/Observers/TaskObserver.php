<?php

namespace App\Observers;

use App\Models\Project;
use App\Models\Task;
use App\Http\Controllers\TaskReminderController;
use Carbon\Carbon;

class TaskObserver
{
    /**
     * Handle the Task "created" event.
     */
    public function created(Task $task): void
    {
        // For newly created tasks, check if they include today and have users assigned
        if ($task->user_ids && !empty($task->user_ids)) {
            $today = Carbon::today();
            $taskIncludesToday = ($task->start_date && $task->end_date) ?
                Carbon::parse($task->start_date)->lte($today) && 
                Carbon::parse($task->end_date)->gte($today) : false;
                
            if ($taskIncludesToday) {
                $reminderController = new TaskReminderController();
                $reminderController->notifyTodayTaskChanges($task, [], $task->user_ids);
            }
        }
    }

    public function creating(Task $task): void
    {
        $project = Project::findOrFail($task->project_id);

        $task->belongs_to_vendor_id = auth()->user()->vendor->id == $project->belongs_to_vendor_id ? auth()->user()->vendor->id : $project->belongs_to_vendor_id;
        $task->created_by_user_id = auth()->user()->id;

        // Handle options - set to null if empty
        if (is_array($task->options) && empty($task->options)) {
            $task->options = null;
        }
    }

    /**
     * Handle the Task "updated" event.
     */
    public function updated(Task $task): void
    {
        // Get the original (before update) and current (after update) values
        $originalUserIds = $task->getOriginal('user_ids') ?? [];
        $newUserIds = $task->user_ids ?? [];

        $originalStartDate = $task->getOriginal('start_date');
        $originalEndDate = $task->getOriginal('end_date');
        $newStartDate = $task->start_date;
        $newEndDate = $task->end_date;
        
        // Check if users or dates changed
        $usersChanged = $originalUserIds != $newUserIds;
        $datesChanged = $originalStartDate != $newStartDate || $originalEndDate != $newEndDate;
        
        // Only process notifications if something relevant changed
        if ($usersChanged || $datesChanged) {
            $today = Carbon::today();
            
            // Check if task spans today (either before or after update)
            $originalTaskIncludesToday = ($originalStartDate && $originalEndDate) ? 
                Carbon::parse($originalStartDate)->lte($today) && 
                Carbon::parse($originalEndDate)->gte($today) : false;
                
            $newTaskIncludesToday = ($newStartDate && $newEndDate) ? 
                Carbon::parse($newStartDate)->lte($today) && 
                Carbon::parse($newEndDate)->gte($today) : false;
            
            // Only send notifications if task includes today (before or after update)
            if ($originalTaskIncludesToday || $newTaskIncludesToday) {
                $reminderController = new TaskReminderController();
                $reminderController->notifyTodayTaskChanges(
                    $task, 
                    $originalUserIds, 
                    $newUserIds,
                    $originalStartDate,
                    $originalEndDate
                );
            }
        }
    }

    public function updating(Task $task): void
    {
        // Handle options - set to null if empty
        if (is_array($task->options) && empty($task->options)) {
            $task->options = null;
        }
    }

    /**
     * Handle the Task "deleted" event.
     */
    public function deleted(Task $task): void
    {
        // When a task is deleted, notify affected users
        $today = Carbon::today();
        $taskIncludesToday = ($task->start_date && $task->end_date) ?
            Carbon::parse($task->start_date)->lte($today) && 
            Carbon::parse($task->end_date)->gte($today) : false;
            
        if ($taskIncludesToday && $task->user_ids && !empty($task->user_ids)) {
            $reminderController = new TaskReminderController();
            $reminderController->notifyTodayTaskChanges($task, $task->user_ids, []);
        }
    }

    /**
     * Handle the Task "restored" event.
     */
    public function restored(Task $task): void
    {
        // When a task is restored, notify newly assigned users
        $today = Carbon::today();
        $taskIncludesToday = ($task->start_date && $task->end_date) ?
            Carbon::parse($task->start_date)->lte($today) && 
            Carbon::parse($task->end_date)->gte($today) : false;
            
        if ($taskIncludesToday && $task->user_ids && !empty($task->user_ids)) {
            $reminderController = new TaskReminderController();
            $reminderController->notifyTodayTaskChanges($task, [], $task->user_ids);
        }
    }

    /**
     * Handle the Task "force deleted" event.
     */
    public function forceDeleted(Task $task): void
    {
        // Similar to deleted, but for force deletes
        $today = Carbon::today();
        $taskIncludesToday = ($task->start_date && $task->end_date) ?
            Carbon::parse($task->start_date)->lte($today) && 
            Carbon::parse($task->end_date)->gte($today) : false;
            
        if ($taskIncludesToday && $task->user_ids && !empty($task->user_ids)) {
            $reminderController = new TaskReminderController();
            $reminderController->notifyTodayTaskChanges($task, $task->user_ids, []);
        }
    }
}
