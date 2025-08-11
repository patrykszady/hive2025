<?php

namespace App\Policies;

use App\Models\Check;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class CheckPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function viewAny(User $user): bool
    {
        // First check if user has a vendor
        if (!$user->vendor) {
            return false;
        }
        
        // Only allow if vendor business_type is NOT 1099
        if ($user->vendor->business_type === '1099') {
            return false;
        }
        
        return true;
    }

    /**
     * Determine whether the user can view the model.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function view(User $user, Check $check): bool
    {
        // Admin can view any check
        if ($user->vendor_role === 'Admin') {
            return true;
        }
        
        // User can view their own checks
        if ($check->user_id === $user->id) {
            return true;
        }
        
        // Get the user's via_vendor_id from pivot
        $userVendorPivot = $user->vendors()
            ->where('vendors.id', $user->vendor->id)
            ->first();
        
        $viaVendorId = $userVendorPivot ? $userVendorPivot->pivot->via_vendor_id : null;
        
        // User can view checks for their via_vendor
        if ($viaVendorId && $check->vendor_id == $viaVendorId) {
            return true;
        }
        
        return false;
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
    public function update(User $user, Check $check): bool
    {
        // Admin can update any check
        if ($user->vendor_role === 'Admin') {
            return true;
        }
        
        return false;
    }

    /**
     * Determine whether the user can delete the model.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function delete(User $user, Check $check): bool
    {
        // Admin can delete check
        if ($user->vendor_role === 'Admin') {
            return true;
        }
        
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function restore(User $user, Check $check): bool
    {
        return $user->vendor_role === 'Admin';
    }

    /**
     * Determine whether the user can permanently delete the model.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function forceDelete(User $user, Check $check): bool
    {
        return false;
    }
}
