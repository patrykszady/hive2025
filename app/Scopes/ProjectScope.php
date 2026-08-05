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
        
        // Skip if user doesn't have a vendor
        if (!$user->vendor) {
            return;
        }
        
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
    }
}
