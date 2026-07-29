<?php

namespace App\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class CheckScope implements Scope
{
    public function apply(Builder $builder, Model $model)
    {
        if (auth()->guest()) {

        } else {
            //->getVendorRole(auth()->user()->vendor->id
            $user = auth()->user();

            //if Check has Paid Employee Timesheets...they shoud show in the Employees Checks?
            if ($user->vendor_role == 'Admin') {
                $builder->where(function ($query) use ($user) {
                    $query->where('belongs_to_vendor_id', $user->vendor->id)
                        ->orWhere('vendor_id', $user->vendor->id);
                });
            } elseif ($user->vendor_role == 'Member') {
                // A member sees only checks payable to THEM: their own
                // payroll/expense checks, or checks written to a vendor company
                // they own. The old rule added `orWhereNull('user_id')`, and
                // most vendor checks carry no user_id — so a member could see
                // nearly the employer's entire check book.
                // Read the pivot directly: the `vendors` relation carries
                // VendorScope, which can hide the member's own company and
                // would silently drop their checks from this list.
                $ownVendorIds = \Illuminate\Support\Facades\DB::table('user_vendor')
                    ->where('user_id', $user->id)
                    ->where('vendor_id', '!=', $user->vendor->id)
                    ->pluck('vendor_id')
                    ->map(fn ($id) => (int) $id)
                    ->values();

                $builder->where('belongs_to_vendor_id', $user->vendor->id)
                    ->where(function ($query) use ($user, $ownVendorIds) {
                        $query->where('user_id', $user->id);

                        if ($ownVendorIds->isNotEmpty()) {
                            $query->orWhereIn('vendor_id', $ownVendorIds->all());
                        }
                    });
            }
        }
    }
}
