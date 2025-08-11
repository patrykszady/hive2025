<?php

namespace App\Policies;

use App\Models\LineItem;
use App\Models\User;

class LineItemPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->vendor_role === 'Admin';

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, LineItem $lineItem): bool
    {
        return $user->vendor_role === 'Admin';
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->vendor_role === 'Admin';
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, LineItem $lineItem): bool
    {
        //
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, LineItem $lineItem): bool
    {
        //
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, LineItem $lineItem): bool
    {
        //
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, LineItem $lineItem): bool
    {
        //
    }
}
