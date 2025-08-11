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
            if ($user->vendor_role == 'Admin') {
                $builder->where('vendor_id', $user->vendor->id);
            } elseif ($user->vendor_role == 'Member') {
                $builder->where('vendor_id', $user->vendor->id)->where('user_id', $user->id);
            }
        }
    }
}
