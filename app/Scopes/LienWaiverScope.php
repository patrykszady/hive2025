<?php

namespace App\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class LienWaiverScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (auth()->guest()) {
            return;
        }

        $user = auth()->user();

        if (! $user->vendor) {
            return;
        }

        // Contractors see waivers they own; vendors see waivers issued to them.
        $builder->where(function ($query) use ($user) {
            $query->where('belongs_to_vendor_id', $user->vendor->id)
                ->orWhere('vendor_id', $user->vendor->id);
        });
    }
}
