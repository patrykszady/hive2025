<?php

namespace App\Observers;

use App\Models\Project;
use App\Models\Task;

class TaskObserver
{
    /**
     * Handle the Task "created" event.
     */
    public function created(Task $task): void
    {
        //
    }

    public function creating(Task $task): void
    {
        $project = Project::findOrFail($task->project_id);

        $task->belongs_to_vendor_id = auth()->user()->vendor->id == $project->belongs_to_vendor_id ? auth()->user()->vendor->id : $project->belongs_to_vendor_id;
        $task->created_by_user_id = auth()->user()->id;
    }

    /**
     * Handle the Task "updated" event.
     */
    public function updated(Task $task): void
    {
        //
    }

    public function updating(Task $task): void
    {
        // $project = Project::findOrFail($task->project_id);

        // $task->belongs_to_vendor_id =  auth()->user()->vendor->id == $project->belongs_to_vendor_id ? auth()->user()->vendor->id : $project->belongs_to_vendor_id;
        // $task->created_by_user_id = auth()->user()->id;
    }

    /**
     * Handle the Task "deleted" event.
     */
    public function deleted(Task $task): void
    {
        //
    }

    /**
     * Handle the Task "restored" event.
     */
    public function restored(Task $task): void
    {
        //
    }

    /**
     * Handle the Task "force deleted" event.
     */
    public function forceDeleted(Task $task): void
    {
        //
    }
}
