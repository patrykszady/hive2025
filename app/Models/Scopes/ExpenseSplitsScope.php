<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class ExpenseSplitsScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     *
     * @return void
     */
    public function apply(Builder $builder, Model $model)
    {
        $user = auth()->user();

        if (is_null($user)) {

        } else {
            //if Admin..all Expenses ... if Member...only expenses the User Paid For....?
            if ($user->primary_vendor->pivot->role_id == 1) {
                $builder->where('belongs_to_vendor_id', auth()->user()->primary_vendor_id);
            } elseif ($user->primary_vendor->pivot->role_id == 2) {
                $builder->where('belongs_to_vendor_id', $user->primary_vendor_id);
            } else {
                // $builder;
            }
        }
    }
}
