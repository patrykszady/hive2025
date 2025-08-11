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
        // Skip for guests/unauthenticated users
        if (auth()->guest()) {
            return;
        }
        
        $user = auth()->user();
        
        // Skip if user doesn't have a vendor
        if (!$user->vendor) {
            return;
        }
        
        // Filter projects that are related to user's vendor
        // through the project_vendor pivot table
        $builder->whereHas('vendors', function ($query) use ($user) {
            $query->where('vendor_id', $user->vendor->id);
        });
        
        // For Admin users, we're done - show all vendor projects
        if ($user->vendor_role === 'Admin') {
            return;
        }
        
        // For Member users, limit by employment start date
        if ($user->vendor_role === 'Member' && isset($user->vendor_pivot->start_date)) {
            $projects_start_date = Carbon::parse($user->vendor_pivot->start_date)
                ->subMonths(6)
                ->format('Y-m-d');
                
            // Apply date filter on projects created after member's start date
            $builder->where('created_at', '>', $projects_start_date);
        }
    }
}
