<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class UserPolicy
{
    use HandlesAuthorization;

    public function admin_login_as_user(User $user)
    {
        //if user is Patryk Szady
        if ($user->id === 1) {
            return true;
        }
    }

    /**
     * Determine whether the user can create something based on their role ID.
     *
     * @return bool
     */
    public function hasAdminRole(User $user): bool
    {
        return $user->vendor_role === 'Admin';
    }

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
     * @return bool
     */
    public function view(User $user, User $model): bool
    {
        // 1. Users can always view their own profile
        if ($user->id === $model->id) {
            return true;
        }

        // Short-circuit if user has no vendor
        if (!$user->vendor) {
            return false;
        }

        // 2. Check if target user belongs to auth user's vendor
        $modelInSameVendor = $model->vendors()
            ->where('vendors.id', $user->vendor->id)
            ->exists();
        
        if ($modelInSameVendor) {
            return true;
        }

        // 3. Check if target user belongs to any client that auth user's vendor has in client_vendor
        $modelInAuthClientsUsers = $model->clients()
            ->whereHas('vendors', function($query) use ($user) {
                $query->where('vendors.id', $user->vendor->id);
            })
            ->exists();
        
        if ($modelInAuthClientsUsers) {
            return true;
        }

        // 4. Check if target user belongs to a vendor that auth user's vendor has in vendors_vendor
        $vendorIds = $user->vendor->vendors()->pluck('vendor_id')->toArray();
        
        if (!empty($vendorIds)) {
            $modelInRelatedVendorUsers = $model->vendors()
                ->whereIn('vendors.id', $vendorIds)
                ->exists();
            
            if ($modelInRelatedVendorUsers) {
                return true;
            }
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
        return $this->hasAdminRole($user);
    }

    public function create_team_member(User $user, $vendor_id): bool
    {
        return $this->hasAdminRole($user) && in_array($user->vendor->business_type, ['Sub', 'DBA']) && $user->vendor->id == $vendor_id;
    }

    public function create_client_member(User $user, Client $client)
    {
        // First check if client has a vendor_id set - if so, prevent adding users
        if (!is_null($client->vendor_id)) {
            return $this->deny('Cannot add users to vendor-linked clients.');
        }
        
        // Otherwise, use the existing conditions
        return $this->hasAdminRole($user) && 
               $user->vendor && 
               $user->vendor->clients()->where('client_id', $client->id)->exists();
    }

    /**
     * Determine whether a client user can update their own contact info.
     * Client users can only update their own email and cell phone.
     */
    public function update_client_member(User $user, User $model): bool
    {
        // Client users can only edit their own profile
        return $user->is_client_user && $user->id === $model->id;
    }

    /**
     * Determine whether the user can update the model.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function update(User $user, User $model): bool
    {
        // Users can always update their own profile
        if ($user->id == $model->id) {
            return true;
        }
        
        // Only admins can update other users
        if (!$this->hasAdminRole($user)) {
            return false;
        }
        
        // Check if target user belongs to a client with vendor_id set
        $clientsWithVendor = $model->clients()->whereNotNull('vendor_id')->get();
        if ($clientsWithVendor->isNotEmpty()) {
            // For each client with vendor_id, check if auth user's vendor matches
            foreach ($clientsWithVendor as $client) {
                // If auth user's vendor doesn't match client's vendor_id, deny update
                if ($user->vendor->id != $client->vendor_id) {
                    // Return false instead of a Response object
                    return false;
                }
            }
        }
        
        // Otherwise allow the update
        return true;
    }

    /**
     * Determine whether the user can delete the model.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function delete(User $user, User $model): bool
    {
        //
    }

    /**
     * Determine whether the user can restore the model.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function restore(User $user, User $model): bool
    {
        //
    }

    /**
     * Determine whether the user can permanently delete the model.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function forceDelete(User $user, User $model): bool
    {
        return false;
    }
}
