<?php

namespace App\Observers;

use App\Models\Project;
use App\Models\ProjectStatus;

class ProjectObserver
{
    public function creating(Project $project): void
    {
        $user = auth()->user();
        $project->belongs_to_vendor_id = $user->vendor->id;
    }

    /**
     * Handle the Project "created" event.
     */
    public function created(Project $project): void
    {
        $project->vendors()->attach($project->belongs_to_vendor_id, ['client_id' => $project->client_id]);

        // Create initial Estimate status (code 2)
        ProjectStatus::create([
            'project_id' => $project->id,
            'belongs_to_vendor_id' => $project->belongs_to_vendor_id,
            'status_code' => 2, // Estimate
            'start_date' => today()->format('Y-m-d'),
        ]);
    }

    /**
     * Handle the Project "updated" event.
     */
    public function updated(Project $project): void
    {
        //
    }

    /**
     * Handle the Project "deleted" event.
     */
    public function deleted(Project $project): void
    {
        //
    }

    /**
     * Handle the Project "restored" event.
     */
    public function restored(Project $project): void
    {
        //
    }

    /**
     * Handle the Project "force deleted" event.
     */
    public function forceDeleted(Project $project): void
    {
        // Delete pivot records
        \DB::table('project_vendor')->where('project_id', $project->id)->delete();
        
        // Delete related records
        $project->statuses()->delete();
        $project->estimates()->delete();
    }
}
