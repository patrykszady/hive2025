<?php

namespace App\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class CompanyEmailsScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     *
     * @return void
     */
    public function apply(Builder $builder, Model $model)
    {
        if (auth()->guest()) {
            // No scope applied for guests
        } elseif (auth()->user()->vendor) {
            $builder->where('vendor_id', auth()->user()->vendor->id);
        } else {
            // User has no vendor (e.g., client user) - return no results
            $builder->whereRaw('1 = 0');
        }
    }
}
