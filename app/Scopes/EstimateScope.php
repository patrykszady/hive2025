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
            $user = auth()->user();

            // Client users or users without a vendor should see all estimates (filtered by project elsewhere)
            if ($user->is_browsing_as_client || ! $user->vendor) {
                return;
            }

            //->whereNotNull('plaid_access_token')
            $builder
                // ->whereJsonContains('sections', ['name' => 'Master Bath'])
                ->where('belongs_to_vendor_id', $user->vendor->id);
        }
    }
}
