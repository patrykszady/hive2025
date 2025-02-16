<?php

namespace App\Models\Scopes;

use App\Models\BankAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class TransactionScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     *
     * @return void
     */
    public function apply(Builder $builder, Model $model)
    {
        $user = auth()->user();

        if (is_null($user)) {

        } else {
            $bank_accounts = BankAccount::pluck('id')->toArray();
            $builder->whereIn('bank_account_id', $bank_accounts);
        }
    }
}
