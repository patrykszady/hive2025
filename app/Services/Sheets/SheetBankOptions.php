<?php

namespace App\Services\Sheets;

use App\Models\Bank;
use Illuminate\Database\Eloquent\Collection;

/**
 * The bank accounts a Sheet can be run against — same filter as
 * App\Livewire\Sheets\SheetsIndex::availableBanks(), shared with the app
 * API's SheetController@index.
 */
class SheetBankOptions
{
    /**
     * @return Collection<int, Bank>
     */
    public function available(): Collection
    {
        return Bank::whereNotNull('plaid_access_token')
            ->with(['accounts' => function ($query) {
                $query->whereIn('type', ['Checking', 'Savings']);
            }])
            ->whereHas('accounts', function ($query) {
                return $query->whereIn('type', ['Checking', 'Savings']);
            })
            ->get()
            ->keyBy('id');
    }
}
