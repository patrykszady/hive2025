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
                $builder->where(function ($query) use ($user) {
                    $query->where('belongs_to_vendor_id', $user->vendor->id)
                        ->orWhere('vendor_id', $user->vendor->id);
                })->where(function ($query) use ($user) {
                    $query->where('user_id', $user->id)
                        ->orWhereNull('user_id');
                });
            }
        }
    }
}
