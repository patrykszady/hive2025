<?php

namespace App\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class ProjectStatusScope implements Scope
{
    public function apply(Builder $builder, Model $model)
    {
        $builder->where('belongs_to_vendor_id', auth()->user()->primary_vendor_id);
    }
}
