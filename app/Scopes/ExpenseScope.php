<?php

namespace App\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class ExpenseScope implements Scope
{
    public function apply(Builder $builder, Model $model)
    {
        $user = auth()->user();

        if ($user) {
            // Client users should not see any expenses
            if ($user->is_browsing_as_client || !$user->vendor) {
                $builder->whereRaw('1 = 0'); // Return no results
                return;
            }

            // Get the user's via_vendor_id from pivot
            $userVendorPivot = $user->vendors()
                ->where('vendors.id', $user->vendor->id)
                ->first();
            
            $viaVendorId = $userVendorPivot ? $userVendorPivot->pivot->via_vendor_id : null;
            
            // if Admin: all vendor expenses
            if ($user->vendor_role == 'Admin') {
                $builder->where(function ($query) use ($user) {
                    $query->where('belongs_to_vendor_id', $user->vendor->id)
                          ->whereNull('belongs_to_client_id');
                });
            } 
            // if Member: only expenses the User Paid For + their via vendor expenses
            elseif ($user->vendor_role == 'Member') {
                $builder->where(function ($query) use ($user, $viaVendorId) {
                    // Regular user PAID BY expenses
                    $query->where(function ($subQuery) use ($user) {
                        $subQuery->where('belongs_to_vendor_id', $user->vendor->id)
                                ->where('paid_by', $user->id)
                                ->whereNull('belongs_to_client_id');
                    });
                    
                    // Add via vendor expenses if applicable
                    if ($viaVendorId) {
                        $query->orWhere('vendor_id', $viaVendorId);
                    }
                });
            }
        }
    }
}
