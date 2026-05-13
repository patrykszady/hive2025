<?php

namespace App\Policies;

use App\Models\LienWaiver;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class LienWaiverPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        // Client-browsing users can view lien waivers for their projects
        if ($user->is_browsing_as_client) {
            return true;
        }

        if ($user->vendor_role !== 'Admin') {
            return false;
        }

        return true;
    }

    public function view(User $user, LienWaiver $lienWaiver): bool
    {
        // Client-browsing users can view lien waivers for their projects
        if ($user->is_browsing_as_client) {
            $clientIds = $user->clients->pluck('id')->toArray();
            return in_array($lienWaiver->project?->client_id, $clientIds);
        }

        if ($user->vendor_role !== 'Admin') {
            return false;
        }

        return true;
    }

    public function create(User $user): bool
    {
        // Only Admins can create lien waivers
        if ($user->vendor_role !== 'Admin') {
            return false;
        }

        return true;
    }

    public function update(User $user, LienWaiver $lienWaiver): bool
    {
        if ($user->vendor_role !== 'Admin') {
            return false;
        }

        return true;
    }

    public function delete(User $user, LienWaiver $lienWaiver): bool
    {
        if ($user->vendor_role !== 'Admin') {
            return false;
        }

        return true;
    }

    public function restore(User $user, LienWaiver $lienWaiver): bool
    {
        if ($user->vendor_role !== 'Admin') {
            return false;
        }

        return true;
    }

    public function forceDelete(User $user, LienWaiver $lienWaiver): bool
    {
        if ($user->vendor_role !== 'Admin') {
            return false;
        }

        return true;
    }
}
