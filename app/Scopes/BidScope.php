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
            $project_ids = Project::pluck('id')->toArray();

            $builder->whereIn('project_id', $project_ids)->where('vendor_id', auth()->user()->vendor->id);
        }
    }
}
