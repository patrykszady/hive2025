<?php

namespace App\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class ProjectScope implements Scope
{
    public function apply(Builder $builder, Model $model)
    {
        // Skip for guests/unauthenticated users
        if (auth()->guest()) {
            return;
        }
        
        $user = auth()->user();

        if ($user->vendor) {
            // Membership in the vendor IS the rule: every user on a vendor sees
            // every project that vendor is on.
            //
            // Members used to be narrowed further to projects created after their
            // start date (less six months), which hid whole jobs from crew who
            // joined mid-project — they could not open the project, its images, or
            // shoot a progress photo into it. Tenure is not what decides whether
            // you are working on a job.
            $builder->whereHas('vendors', function ($query) use ($user) {
                $query->where('vendor_id', $user->vendor->id);
            });

            return;
        }

        // A signed-in user with no vendor (a homeowner in the client portal)
        // may only see the projects belonging to the client record(s) they
        // are personally linked to.
        $clientIds = $user->clients()->withoutGlobalScope(ClientScope::class)->pluck('clients.id');

        if ($clientIds->isEmpty()) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->whereIn($model->getTable().'.client_id', $clientIds);
    }
}
