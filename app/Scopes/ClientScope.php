<?php

namespace App\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class ClientScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     * Only show clients that are associated with the authenticated user's vendor
     * through the client_vendor pivot table.
     *
     * @return void
     */
    public function apply(Builder $builder, Model $model)
    {
        // Skip for guests/unauthenticated users
        if (auth()->guest()) {
            return;
        }
        
        $user = auth()->user();
        
        // Skip if user doesn't have a vendor
        if (!$user->vendor) {
            return;
        }
        
        // Only show clients that are related to user's vendor
        // through the client_vendor pivot table
        $builder->whereHas('vendors', function ($query) use ($user) {
            $query->where('vendor_id', $user->vendor->id);
        });
    }
}
