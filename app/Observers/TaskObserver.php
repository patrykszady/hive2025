<?php

namespace App\Observers;

use App\Jobs\SendBatchVendorAvailabilitySms;
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

        // Queue vendor availability SMS if vendor is assigned
        $this->queueVendorNotificationIfNeeded($task);
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

        // Queue vendor availability SMS if vendor was just assigned or dates changed
        $originalVendorId = $task->getOriginal('vendor_id');
        $newVendorId = $task->vendor_id;
        $vendorChanged = $originalVendorId != $newVendorId;
        $datesChanged = $task->getOriginal('start_date') != $task->start_date 
            || $task->getOriginal('end_date') != $task->end_date;

        if ($vendorChanged || $datesChanged) {
            $this->queueVendorNotificationIfNeeded($task);
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

    /**
     * Queue vendor availability SMS notification if conditions are met.
     * The job will be dispatched with a 1 hour delay to allow for task edits.
     * ShouldBeUnique ensures only one job per vendor, consolidating multiple tasks
     * assigned to the same vendor within the hour into a single SMS.
     */
    private function queueVendorNotificationIfNeeded(Task $task): void
    {
        // Must have a vendor assigned
        if (!$task->vendor_id) {
            return;
        }

        // Vendor status should be null (not already requested/confirmed/rejected)
        if ($task->vendor_status !== null) {
            return;
        }

        // Task should have dates set
        if (!$task->start_date) {
            return;
        }

        // Task start date should be in the future (or today)
        if ($task->start_date->isPast() && !$task->start_date->isToday()) {
            return;
        }

        // Dispatch the job with a 1 hour delay, keyed by vendor_id
        // Using ShouldBeUnique, if another task for the same vendor is created within the hour,
        // the new dispatch will be ignored. The original job will run after 1 hour and
        // find ALL eligible tasks for that vendor at that time.
        SendBatchVendorAvailabilitySms::dispatch($task->vendor_id)
            ->delay(now()->addHour());
    }
}
