<?php

namespace App\Policies;

use App\Models\User;
use App\Models\VendorDoc;

class VendorDocPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        if ($user->primary_vendor->pivot->role_id == 1) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, VendorDoc $vendorDoc): bool
    {
        if ($user->primary_vendor->pivot->role_id == 1) {
            return true;
        } else {
            return false;
        }
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
    public function update(User $user, VendorDoc $vendorDoc): bool
    {
        //
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, VendorDoc $vendorDoc): bool
    {
        //
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, VendorDoc $vendorDoc): bool
    {
        //
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, VendorDoc $vendorDoc): bool
    {
        //
    }
}
