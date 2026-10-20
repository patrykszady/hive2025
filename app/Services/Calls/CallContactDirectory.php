<?php

namespace App\Services\Calls;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Who shows up in "place a new call" — the web's CallList::contactUsers()
 * (team/clients/subs this vendor would plausibly call) and
 * CallList::callRecipients() (this vendor's admins, for the multi-admin ring
 * recipient choice) — shared with the app's calls/contacts endpoint.
 */
class CallContactDirectory
{
    /**
     * @return Collection<int, User>
     */
    public function contacts(User $user): Collection
    {
        $vendor = $user->vendor;

        if (! $vendor) {
            return collect();
        }

        $vendorIds = $vendor->vendors()->pluck('vendors.id')->push($vendor->id)->unique();

        return User::whereNotNull('cell_phone')
            ->where('cell_phone', '!=', '')
            ->where(function ($query) use ($vendorIds, $vendor) {
                $query->whereHas('vendors', fn ($q) => $q->whereIn('vendors.id', $vendorIds))
                    ->orWhereHas('clients', fn ($q) => $q->whereHas('vendors', fn ($cq) => $cq->where('vendors.id', $vendor->id)));
            })
            ->orderBy('first_name')
            ->get();
    }

    /**
     * @return Collection<int, User>
     */
    public function ringRecipients(User $user): Collection
    {
        $vendor = $user->vendor;

        if (! $vendor) {
            return collect();
        }

        return $vendor->getAdminUsersWithCellPhones()->sortBy('first_name');
    }
}
