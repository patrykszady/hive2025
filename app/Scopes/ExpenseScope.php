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

        if (is_null($user)) {
            // $builder;
        } else {
            //if Admin..all Expenses ... if Member...only expenses the User Paid For....?
            if ($user->primary_vendor->pivot->role_id == 1) {
                $builder->where('belongs_to_vendor_id', $user->primary_vendor_id);
            } elseif ($user->primary_vendor->pivot->role_id == 2) {
                $builder->where('belongs_to_vendor_id', $user->primary_vendor_id)->where('paid_by', $user->id);
            } else {

            }
        }
    }
}
