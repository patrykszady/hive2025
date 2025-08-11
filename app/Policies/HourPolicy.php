<?php

namespace App\Policies;

use App\Models\Hour;
use App\Models\User;

class HourPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return false;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Hour $hour): bool
    {
        return false;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        // Users with 1099 business type cannot create hours
        if ($user->vendor?->business_type === '1099') {
            return false;
        }
        
        return true;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Hour $hour): bool
    {
        //
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Hour $hour): bool
    {
        //
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Hour $hour): bool
    {
        //
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Hour $hour): bool
    {
        //
    }
}
