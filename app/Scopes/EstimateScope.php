<?php

namespace App\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class EstimateScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     */
    public function apply(Builder $builder, Model $model): void
    {
        if (auth()->guest()) {

        } else {
            //->whereNotNull('plaid_access_token')
            $builder
                // ->whereJsonContains('sections', ['name' => 'Master Bath'])
                ->where('belongs_to_vendor_id', auth()->user()->vendor->id);
        }
    }
}
