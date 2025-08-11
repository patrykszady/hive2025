<?php

namespace App\Scopes;

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
            if ($user->vendor_role == 'Admin') {
                $builder->where('belongs_to_vendor_id', auth()->user()->vendor->id);
            } elseif ($user->vendor_role == 'Member') {
                $builder->where('belongs_to_vendor_id', $user->vendor->id);
            } else {
                // $builder;
            }
        }
    }
}
