<?php

namespace App\Services\Checks;

use App\Models\BankAccount;
use App\Models\Check;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

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
        $latest = Check::whereIn('bank_account_id', $this->siblingAccountIds($bankAccount))
            ->where('check_type', 'Check')
            ->orderByDesc('date')
            ->orderByDesc('created_at')
            ->first();

        return $latest ? ((int) $latest->check_number) + 1 : null;
    }

    /**
     * Every account under the same physical bank as $bankAccount (a
     * re-linked Plaid item duplicates accounts under one bank, e.g.
     * Citibank ids 10 and 17) — the scope a paper check's number must stay
     * unique across, shared by HandlesChecks::handlesChecksRules()'s
     * check_number uniqueness rule and the Hive Contractors app's own
     * version of it (StoreExpenseRequest, StoreVendorPaymentRequest).
     *
     * @return list<int>
     */
    public function siblingAccountIds(BankAccount $bankAccount): array
    {
        return $bankAccount->bank?->accounts()->withoutGlobalScopes()->pluck('id')->toArray() ?? [$bankAccount->id];
    }

    /**
     * A check_number uniqueness rule scoped across every sibling account
     * under the submitted bank account's own bank (siblingAccountIds()),
     * paper checks only, excluding soft-deleted rows — the one shared
     * version of the scoping App\Traits\HandlesChecks::handlesChecksRules()
     * inlines for the web's own Livewire forms, and that
     * StoreVendorPaymentRequest/StoreTimesheetPaymentRequest now reuse so a
     * re-linked Plaid item's duplicate account can never collide with a
     * number already recorded under the same physical bank.
     */
    public function uniqueRule(?int $bankAccountId): Unique
    {
        return Rule::unique('checks', 'check_number')->where(function ($query) use ($bankAccountId) {
            $query->where('check_type', 'Check')->whereNull('deleted_at');

            $account = $bankAccountId ? BankAccount::withoutGlobalScopes()->find($bankAccountId) : null;

            if ($account) {
                $query->whereIn('bank_account_id', $this->siblingAccountIds($account));
            } else {
                // No bank account submitted (or an invalid one, already
                // caught by the bank_account_id rule) — fall back to this
                // one id so the uniqueness check still scopes narrowly
                // rather than matching every bank's check numbers.
                $query->where('bank_account_id', $bankAccountId);
            }
        });
    }
}
