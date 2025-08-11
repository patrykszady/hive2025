<?php

namespace App\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class HourScope implements Scope
{
    public function apply(Builder $builder, Model $model)
    {
        $user = auth()->user();

        //if Admin..all Hours ... if Member...only hours the User belongs to....?
        if ($user->vendor_role == 'Admin') {
            $builder->where('vendor_id', $user->vendor->id);
        } elseif ($user->vendor_role == 'Member') {
            $builder->where('vendor_id', $user->vendor->id)->where('user_id', $user->id);
        }
    }
}
