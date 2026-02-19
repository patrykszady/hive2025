<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class ClientPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function viewAny(User $user): bool
    {
        return $user->vendor_role === 'Admin';
    }

    /**
     * Determine whether the user can view the model.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function view(User $user, Client $client): bool
    {
        // Client users can only view clients they are associated with
        if ($user->is_client_user) {
            return $user->clients()->where('clients.id', $client->id)->exists();
        }

        // Vendor users can view all clients (scoped by vendor elsewhere)
        return true;
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
    public function update(User $user, Client $client): bool
    {
        // If client has a vendor_id set, it's a vendor client and cannot be modified
        if (!is_null($client->vendor_id)) {
            return false;
        }
        
        // Otherwise, check if user is an Admin
        return $user->vendor_role === 'Admin';
    }

    /**
     * Determine whether the user can delete the model.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function delete(User $user, Client $client): bool
    {
        if ($user->vendor_role !== 'Admin') {
            return false;
        }

        // Only allow deletion of clients with no associated data
        return $client->projects()->doesntExist();
    }

    /**
     * Determine whether the user can restore the model.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function restore(User $user, Client $client): bool
    {
        //
    }

    /**
     * Determine whether the user can permanently delete the model.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function forceDelete(User $user, Client $client): bool
    {
        //
    }
}
