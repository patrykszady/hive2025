<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Vendor;
use Illuminate\Auth\Access\HandlesAuthorization;

class VendorPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function viewAny(User $user): bool
    {
        //
    }

    /**
     * Determine whether the user can view the model.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function view(User $user, Vendor $vendor): bool
    {
        if (!$user->vendor) {
            return false; // no primary vendor context
        }

        // Own vendor always allowed (middleware will redirect display to dashboard)
        if ($user->vendor->id === $vendor->id) {
            return true;
        }

        // Allow any vendor that belongs to the user's primary vendor (company tree)
        if ($user->vendor->relationLoaded('vendors')) {
            return $user->vendor->vendors->contains('id', $vendor->id);
        }

        return $user->vendor->vendors()->where('vendors.id', $vendor->id)->exists();
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
    public function update(User $user, Vendor $vendor): bool
    {
        return $user->vendor_role === 'Admin';
    }

    /**
     * Determine whether the user can delete the model.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function delete(User $user, Vendor $vendor): bool
    {
        //
    }

    /**
     * Determine whether the user can restore the model.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function restore(User $user, Vendor $vendor): bool
    {
        //
    }

    /**
     * Determine whether the user can permanently delete the model.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function forceDelete(User $user, Vendor $vendor): bool
    {
        //
    }

    /**
     * Determine whether the user can view vendor options settings.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function viewOptions(User $user): bool
    {
        return $user->vendor_role === 'Admin';
    }
}
