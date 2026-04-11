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
        // Client-browsing users can only view projects belonging to their personal clients
        // Exclude vendor-owned clients (where client.vendor_id matches a vendor the user belongs to)
        if ($user->is_browsing_as_client) {
            $userVendorIds = $user->vendors()->withoutGlobalScopes()->pluck('vendors.id');

            $clientIds = $user->clients()
                ->where(function ($query) use ($userVendorIds) {
                    $query->whereNull('clients.vendor_id')
                        ->orWhereNotIn('clients.vendor_id', $userVendorIds);
                })
                ->pluck('clients.id');

            return $project->client_id && $clientIds->contains($project->client_id);
        }

        return true;
    }

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
        return $user->vendor_role === 'Admin';
    }

    /**
     * Determine whether the user can delete the model.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function delete(User $user, Project $project): bool
    {
        // Only admins can delete
        if ($user->vendor_role !== 'Admin') {
            return false;
        }

        // Cannot delete if project has payments, timesheets, hours, tasks, expenses, or distributions
        if ($project->payments()->exists() ||
            $project->timesheets()->exists() ||
            $project->hours()->exists() ||
            $project->tasks()->exists() ||
            $project->expenses()->exists() ||
            $project->expenseSplits()->exists() ||
            $project->distributions()->exists()) {
            return false;
        }

        return true;
    }

    /**
     * Determine whether the user can restore the model.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function restore(User $user, Project $project): bool
    {
        return $user->vendor_role === 'Admin';
    }

    /**
     * Determine whether the user can permanently delete the model.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function forceDelete(User $user, Project $project): bool
    {
        // Only admins can delete
        if ($user->vendor_role !== 'Admin') {
            return false;
        }

        // Cannot delete if project has payments, timesheets, hours, tasks, expenses, or distributions
        if ($project->payments()->exists() ||
            $project->timesheets()->exists() ||
            $project->hours()->exists() ||
            $project->tasks()->exists() ||
            $project->expenses()->exists() ||
            $project->expenseSplits()->exists() ||
            $project->distributions()->exists()) {
            return false;
        }

        return true;
    }
    
    /**
     * Determine whether the user can view financial details of the project.
     * Like update but without the client mismatch restriction.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function viewMaterials(User $user, Project $project): bool
    {
        if ($user->vendor_role === 'Admin') {
            return true;
        }

        if ($user->is_browsing_as_client) {
            $clientIds = $user->clients()->pluck('clients.id');

            return $project->client_id && $clientIds->contains($project->client_id);
        }

        return false;
    }

    public function viewFinancials(User $user, Project $project): bool
    {
        // Vendors with admin role can view financials
        if ($user->vendor_role === 'Admin') {
            return true;
        }

        // Client-browsing users can view financials for their own projects
        if ($user->is_browsing_as_client) {
            $clientIds = $user->clients()->pluck('clients.id');
            return $project->client_id && $clientIds->contains($project->client_id);
        }

        return false;
    }
}
