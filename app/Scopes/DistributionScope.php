<?php

namespace App\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class DistributionScope implements Scope
{
    public function apply(Builder $builder, Model $model)
    {
        if (auth()->guest()) {

        } else {
            $builder->where('vendor_id', auth()->user()->primary_vendor_id);
        }
    }
}
