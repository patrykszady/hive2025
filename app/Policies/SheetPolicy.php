<?php

namespace App\Policies;

use App\Models\Sheet;
use App\Models\User;

class SheetPolicy
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
    public function view(User $user, Sheet $sheet): bool
    {
        return $user->primary_vendor->pivot->role_id === 1;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        //
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Sheet $sheet): bool
    {
        //
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Sheet $sheet): bool
    {
        //
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Sheet $sheet): bool
    {
        //
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Sheet $sheet): bool
    {
        //
    }
}
