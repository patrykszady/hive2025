<?php

namespace App\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class PaymentScope implements Scope
{
    public function apply(Builder $builder, Model $model)
    {
        if (auth()->guest()) {

        } else {
            $user = auth()->user();

            // Client users or users without a vendor see all payments (filtered by project elsewhere)
            if ($user->is_browsing_as_client || !$user->vendor) {
                return;
            }

            $builder->where('belongs_to_vendor_id', $user->vendor->id);
        }
    }
}
