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
    public function view(User $user, User $model): bool
    {
        //
    }

    /**
     * Determine whether the user can create models.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function create(User $user): bool
    {
        return $user->primary_vendor->pivot->role_id === 1;
    }

    public function create_team_member(User $user, $vendor_id)
    {
        if ($user->primary_vendor->pivot->role_id == 1 && in_array($user->vendor->business_type, ['Sub', 'DBA']) && $user->vendor->id == $vendor_id) {
            return true;
        }
    }

    public function create_client_member(User $user, Client $client)
    {
        if ($client->vendor()->exists()) {
            return false;
        } else {
            if ($user->primary_vendor->pivot->role_id == 1 && in_array($user->vendor->business_type, ['Sub', 'DBA'])) {
                return true;
            } else {
                return false;
            }
        }
    }

    /**
     * Determine whether the user can update the model.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function update(User $user, User $model): bool
    {
        if ($user->id == $model->id) {
            return true;
        } else {
            if ($user->primary_vendor->pivot->role_id == 1) {
                return true;
            } else {
                return false;
            }
        }
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
        //
    }
}
