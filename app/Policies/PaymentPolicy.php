<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class PaymentPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        // Client users can view payments for their projects
        if ($user->is_client_user) {
            return true;
        }

        if ($user->vendor_role !== 'Admin') {
            return false;
        }

        return true;
    }
    
    public function view(User $user, Payment $payment): bool
    {
        // Client users can view payments for their projects
        if ($user->is_client_user) {
            $clientIds = $user->clients->pluck('id')->toArray();
            return in_array($payment->project?->client_id, $clientIds);
        }

        if ($user->vendor_role !== 'Admin') {
            return false;
        }

        return true;
    }

    public function create(User $user): bool
    {
        // Only Admins can create payments
        if ($user->vendor_role !== 'Admin') {
            return false;
        }

        return true;
    }

    /**
     * Determine whether the user can update the payment.
     *
     * @return bool
     */
    public function update(User $user, Payment $payment): bool
    {
        // First check if user is Admin
        if ($user->vendor_role !== 'Admin') {
            return false;
        }
        
        // Skip check if payment doesn't have a creator ID
        if (!$payment->created_by_user_id) {
            return false;
        }
        
        // If user doesn't have a vendor, they can't update payments
        if (!$user->vendor) {
            return false;
        }
        
        // Check if the payment creator is part of the user's vendor team
        $vendorUserIds = $user->vendor->users()->pluck('users.id')->toArray();
        
        // Only allow update if creator is in the same vendor team
        if (!in_array($payment->created_by_user_id, $vendorUserIds)) {
            return false;
        }

        return true;
    }
}
