<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class ProjectPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function view(User $user, Project $project): bool
    {
        return true;
    }

    /**
     * Determine whether the user can create models.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function create(User $user): bool
    {
        return $user->vendor_role === 'Admin';
    }

    /**
     * Determine whether the user can update the model.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function update(User $user, Project $project): bool
    {
        // First check if user has admin role
        if ($user->vendor_role !== 'Admin') {
            return false;
        }
        
        // Check if there's a client_id mismatch
        // This prevents updates when the direct client_id doesn't match the related client's id
        if ($project->client_id && $project->client && $project->client_id != $project->client->id) {
            return false;
        }
        
        return true;
    }

    /**
     * Determine whether the user can delete the model.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function delete(User $user, Project $project): bool
    {
        //
    }

    /**
     * Determine whether the user can restore the model.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function restore(User $user, Project $project): bool
    {
        //
    }

    /**
     * Determine whether the user can permanently delete the model.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function forceDelete(User $user, Project $project): bool
    {
        //
    }
    
    /**
     * Determine whether the user can view financial details of the project.
     * Like update but without the client mismatch restriction.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function viewFinancials(User $user, Project $project): bool
    {
        // Only check if user has admin role
        return $user->vendor_role === 'Admin';
    }
}
