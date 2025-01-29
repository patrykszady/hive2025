<?php

namespace App\Policies;

use App\Models\TransactionBlukMatch;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class TransactionBulkMatchPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function viewAny(User $user): bool
    {
        return $user->primary_vendor->pivot->role_id === 1;
    }

    /**
     * Determine whether the user can view the model.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function view(User $user, TransactionBlukMatch $transactionBlukMatch): bool
    {
        // return false;
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

    /**
     * Determine whether the user can update the model.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function update(User $user, TransactionBlukMatch $transactionBlukMatch): bool
    {
        return $user->primary_vendor->pivot->role_id === 1;
    }

    /**
     * Determine whether the user can delete the model.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function delete(User $user, TransactionBlukMatch $transactionBlukMatch): bool
    {
        //
    }

    /**
     * Determine whether the user can restore the model.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function restore(User $user, TransactionBlukMatch $transactionBlukMatch): bool
    {
        //
    }

    /**
     * Determine whether the user can permanently delete the model.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function forceDelete(User $user, TransactionBlukMatch $transactionBlukMatch): bool
    {
        //
    }
}
