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

        if ($user->vendor) {
            // Only show clients that are related to user's vendor
            // through the client_vendor pivot table
            $builder->whereHas('vendors', function ($query) use ($user) {
                $query->where('vendor_id', $user->vendor->id);
            });

            return;
        }

        // A signed-in user with no vendor (a homeowner in the client portal)
        // may only ever see the client record(s) they are personally linked
        // to. Bypass this same scope on the pivot lookup itself, or it would
        // recurse back into this branch forever.
        $clientIds = $user->clients()->withoutGlobalScope(self::class)->pluck('clients.id');

        if ($clientIds->isEmpty()) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->whereIn($model->getQualifiedKeyName(), $clientIds);
    }
}
