<?php

namespace App\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class EmailTrackingScope implements Scope
{
    public function apply(Builder $builder, Model $model)
    {
        $user = auth()->user();

        if (! $user || ! $user->vendor) {
            return;
        }

        $builder->where('belongs_to_vendor_id', $user->vendor->id);
    }
}
