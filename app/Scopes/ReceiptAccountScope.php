<?php

namespace App\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class ReceiptAccountScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     *
     * @return void
     */
    public function apply(Builder $builder, Model $model)
    {
        $user = auth()->user();

        if ($user && $user->vendor) {
            $builder->where('belongs_to_vendor_id', $user->vendor->id);
        }
    }
}
