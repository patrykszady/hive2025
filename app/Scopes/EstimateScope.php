<?php

namespace App\Scopes;

use App\Models\Project;
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

            // A signed-in user with no vendor (a homeowner in the client
            // portal) may only see estimates for projects belonging to the
            // client record(s) they are personally linked to. ProjectScope
            // already narrows Project::query() to those same projects.
            if ($user->is_browsing_as_client || ! $user->vendor) {
                $projectIds = Project::query()->pluck('id');

                if ($projectIds->isEmpty()) {
                    $builder->whereRaw('1 = 0');

                    return;
                }

                $builder->whereIn('project_id', $projectIds);

                return;
            }

            //->whereNotNull('plaid_access_token')
            $builder
                // ->whereJsonContains('sections', ['name' => 'Master Bath'])
                ->where('belongs_to_vendor_id', $user->vendor->id);
        }
    }
}
