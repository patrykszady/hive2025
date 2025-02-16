<?php

namespace App\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class TimesheetScope implements Scope
{
    public function apply(Builder $builder, Model $model)
    {
        if (auth()->guest()) {

        } else {
            $user = auth()->user();

            //if Admin..all Expenses ... if Member...only expenses the User Paid For....?
            if ($user->primary_vendor->pivot->role_id == 1) {
                $builder->where('vendor_id', $user->primary_vendor_id);
            } elseif ($user->primary_vendor->pivot->role_id == 2) {
                $builder->where('vendor_id', $user->primary_vendor_id)->where('user_id', $user->id);
            }
        }
    }
}
