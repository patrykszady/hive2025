<?php

namespace App\Scopes;

use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class BidScope implements Scope
{
    public function apply(Builder $builder, Model $model)
    {
        if (auth()->guest()) {

        } else {
            $user = auth()->user();

            // Client users or users without a vendor should see all bids for the project
            if ($user->is_browsing_as_client || !$user->vendor) {
                return;
            }

            $project_ids = Project::pluck('id')->toArray();

            $builder->whereIn('project_id', $project_ids)->where('vendor_id', $user->vendor->id);
        }
    }
}
