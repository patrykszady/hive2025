<?php

namespace App\Policies;

use App\Models\Expense;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class ExpensePolicy
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
    public function view(User $user, Expense $expense): bool
    {
        // Admin can see all expenses belonging to their vendor
        if ($user->vendor_role === 'Admin') {
            // Direct vendor match
            if ($expense->belongs_to_vendor_id == $user->vendor->id) {
                return true;
            }

            // Sub-vendor access: expense belongs to a sub-contractor of the user's vendor
            if ($expense->belongs_to_vendor_id) {
                $belongsToVendor = \App\Models\Vendor::find($expense->belongs_to_vendor_id);
                if ($belongsToVendor && $belongsToVendor->business_type === 'Sub') {
                    return true;
                }
            }

            // Project-based access: expense is on a project belonging to this vendor
            if ($expense->project_id) {
                $project = \App\Models\Project::find($expense->project_id);
                if ($project && $project->belongs_to_vendor_id == $user->vendor->id) {
                    return true;
                }
            }
        }
        
        // Get the user's via_vendor_id from pivot
        $userVendorPivot = $user->vendors()
            ->where('vendors.id', $user->vendor->id)
            ->first();
        
        $viaVendorId = $userVendorPivot ? $userVendorPivot->pivot->via_vendor_id : null;
        
        // Check if this is a via vendor expense
        if ($viaVendorId && $expense->vendor_id == $viaVendorId) {
            return true;
        }
        
        // Regular user can see expenses they paid for
        if ($expense->belongs_to_vendor_id == $user->vendor->id && $expense->paid_by == $user->id) {
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
    public function update(User $user, Expense $expense): bool
    {
        // Admin can update any expense
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
    public function delete(User $user, Expense $expense): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function restore(User $user, Expense $expense): bool
    {
        return $user->vendor_role === 'Admin';
    }

    /**
     * Determine whether the user can permanently delete the model.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function forceDelete(User $user, Expense $expense): bool
    {
        return false;
    }
}
