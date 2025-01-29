<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class PaymentPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        if (request()->route()->action['as'] == 'projects.show') {
            $vendor_id = request()->route()->project->client->vendor_id;
        } elseif (request()->route()->action['as'] == 'payments.create') {
            $vendor_id = request()->route()->client->vendor_id;
        } elseif (request()->route()->action['as'] == 'payments.index') {
            $vendor_id = false;
        } else {
            $vendor_id = true;
        }

        if ($vendor_id) {
            return false;
        } else {
            return true;
        }
    }
}
