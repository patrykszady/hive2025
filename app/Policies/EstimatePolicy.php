<?php

namespace App\Policies;

use App\Models\Estimate;
use App\Models\Project;
use App\Models\User;

class EstimatePolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->primary_vendor->pivot->role_id === 1;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Estimate $estimate): bool
    {
        return $user->primary_vendor->pivot->role_id === 1;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user, Project $project): bool
    {
        //can create if project->last_status is NOT X Y and Z
        if ($user->primary_vendor->pivot->role_id === 1 && ! in_array($project->last_status->title, ['Complete', 'Service Call', 'Service Call Complete', 'Cancelled', 'VIEW_ONLY'])) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Estimate $estimate): bool
    {
        //
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Estimate $estimate): bool
    {
        //
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Estimate $estimate): bool
    {
        //
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Estimate $estimate): bool
    {
        //
    }
}
