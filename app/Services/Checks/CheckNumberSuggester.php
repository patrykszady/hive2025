<?php

namespace App\Services\Checks;

use App\Models\BankAccount;
use App\Models\Check;

/**
 * The next paper check number for a bank account — mirrors
 * App\Traits\HandlesChecks::autoCheckNumber() exactly (every account sharing
 * the same bank, latest-dated paper check + 1), shared by the timesheet and
 * vendor payment "pay" screens on both the web and the Hive Contractors
 * app, so a suggested number is never a step behind on one surface.
 */
class CheckNumberSuggester
{
    /** Null when that bank has never had a paper check recorded — callers leave the field blank rather than guess. */
    public function next(BankAccount $bankAccount): ?int
    {
        $siblingAccountIds = $bankAccount->bank?->accounts()->withoutGlobalScopes()->pluck('id')->toArray() ?? [$bankAccount->id];

        $latest = Check::whereIn('bank_account_id', $siblingAccountIds)
            ->where('check_type', 'Check')
            ->orderByDesc('date')
            ->orderByDesc('created_at')
            ->first();

        return $latest ? ((int) $latest->check_number) + 1 : null;
    }
}
