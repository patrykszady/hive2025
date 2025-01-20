<?php

namespace App\Models\Scopes;

// use App\Models\Client;
// use App\Models\Vendor;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class ClientScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     *
     * @return void
     */
    public function apply(Builder $builder, Model $model)
    {
        if (auth()->guest()) {

        } else {
            if (auth()->user()->vendor) {
                $builder->whereHas('vendors', function ($q) {
                    $q->where('vendor_id', '=', auth()->user()->vendor->id);
                });
            }
        }
    }
}
