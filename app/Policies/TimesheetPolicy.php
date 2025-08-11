<?php

namespace App\Policies;

use App\Models\Timesheet;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class TimesheetPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function viewAny(User $user): bool
    {
        // Admins can list timesheets only if their vendor is not 1099.
        // Non-admins can list (listing should be filtered to their own records).
        return $user->vendor_role === 'Admin'
            ? ($user->vendor?->business_type !== '1099')
            : true;
    }

    /**
     * Determine whether the user can view the model.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function view(User $user, Timesheet $timesheet): bool
    {
        // Owner can view their own timesheet
        if ($timesheet->user_id === $user->id) {
            return true;
        }

        // Admins can view if vendor is not 1099
        if ($user->vendor_role === 'Admin' && $user->vendor?->business_type !== '1099') {
            return true;
        }

        return false;
    }

    public function viewAnyPayment(User $user): bool
    {
        return $user->vendor_role === 'Admin'
            && $user->vendor?->business_type !== '1099';
    }

    public function viewPayment(User $user, User $paymentUser): bool
    {
        // No payments visibility for 1099 vendors (even Admins)
        if ($user->vendor?->business_type === '1099') {
            return false;
        }

        // Admins on non-1099 vendors can view payments
        if ($user->vendor_role === 'Admin') {
            return true;
        }

        // Otherwise, users can view their own payment page
        return $user->id === $paymentUser->id;
    }

    /**
     * Determine whether the user can create models.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function create(User $user): bool
    {
        return false;
    }

    /**
     * Determine whether the user can update the model.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function update(User $user, Timesheet $timesheet): bool
    {
        //
    }

    /**
     * Determine whether the user can delete the model.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function delete(User $user, Timesheet $timesheet): bool
    {
        //
    }

    /**
     * Determine whether the user can restore the model.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function restore(User $user, Timesheet $timesheet): bool
    {
        //
    }

    /**
     * Determine whether the user can permanently delete the model.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function forceDelete(User $user, Timesheet $timesheet): bool
    {
        //
    }
}
