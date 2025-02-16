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
        if ($user->primary_vendor->pivot->role_id == 1) {
            $builder->where('vendor_id', $user->vendor->id);
        } elseif ($user->primary_vendor->pivot->role_id == 2) {
            $builder->where('vendor_id', $user->vendor->id)->where('user_id', $user->id);
        }
    }
}
