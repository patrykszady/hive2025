<?php

namespace App\Scopes;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class ProjectScope implements Scope
{
    public function apply(Builder $builder, Model $model)
    {
        if (auth()->guest()) {

        } else {
            $user = auth()->user();
            $user_vendor_pivot = $user->primary_vendor->pivot;

            $builder->withWhereHas('vendor', function ($query) use ($user) {
                $query->where('vendor_id', $user->vendor->id);
            });

            //where client/vendor
            //Admin
            if ($user_vendor_pivot->role_id == 1) {
                // dd($user_vendor_pivot);
                // $builder->where('belongs_to_vendor_id', $user->primary_vendor_id);

                //shows all projects
                // $builder;
                //Member
            } elseif ($user_vendor_pivot->role_id == 2) {
                //03-15-2023  and any active projects despite how long ago they were created...
                $projects_start_date = Carbon::parse($user_vendor_pivot->start_date)->subMonths(6)->format('Y-m-d');
                // $projects_end_date = Carbon::parse($user->vendor->auth_user_role->first()->pivot->end_date);

                //only show projects since employment started ..minus 6 months (why 6 months?)
                //whereBetween start and end dates
                // $builder->whereBetween('created_at', [$projects_start_date, $projects_end_date]);
                $builder->where('created_at', '>', $projects_start_date);
            }
        }
    }
}
