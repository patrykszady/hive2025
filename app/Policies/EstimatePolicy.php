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
        // Client-browsing users can view estimates for their projects
        if ($user->is_browsing_as_client) {
            return true;
        }

        // First check if user is Admin
        if ($user->vendor_role !== 'Admin') {
            return false;
        }
        
        // Then check if user's vendor has business_type of 1099
        if ($user->vendor && $user->vendor->business_type === '1099') {
            return false;
        }
        
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Estimate $estimate): bool
    {
        // Client-browsing users can view estimates for their client projects
        if ($user->is_browsing_as_client) {
            $clientId = $estimate->project?->client?->id;

            return $clientId !== null
                && $user->clients()->whereKey($clientId)->exists();
        }

        // First check if user is Admin
        if ($user->vendor_role !== 'Admin') {
            return false;
        }
        
        // Then check if user's vendor has business_type of 1099
        if ($user->vendor && $user->vendor->business_type === '1099') {
            return false;
        }
        
        return true;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user, Project $project): bool
    {
        //can create if project->latestStatus is NOT X Y and Z
        if ($user->vendor_role === 'Admin' && ! in_array($project->latestStatus?->status_code, [7, 8, 10, 11])) { // Not Complete, Service Call, Cancelled, VIEW_ONLY
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
        // First check if user is Admin
        if ($user->vendor_role !== 'Admin') {
            return false;
        }
        
        // Then check if user's vendor has business_type of 1099
        if ($user->vendor && $user->vendor->business_type === '1099') {
            return false;
        }
        
        return true;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Estimate $estimate): bool
    {
        // First check if user is Admin
        if ($user->vendor_role !== 'Admin') {
            return false;
        }
        
        // Then check if user's vendor has business_type of 1099
        if ($user->vendor && $user->vendor->business_type === '1099') {
            return false;
        }
        
        // Check if the estimate's project is in a state that allows deletion
        if (in_array($estimate->project->latestStatus?->status_code, [7, 8, 10, 11])) { // Complete, Service Call, Cancelled, VIEW_ONLY
            return false;
        }
        
        return true;
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
