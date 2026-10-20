<?php

namespace App\Services\Banks;

use App\Models\BankAccount;
use App\Models\Check;
use Illuminate\Support\Collection;

/**
 * Groups bank accounts (account_number -> type -> representative account +
 * uncleared checks) for one or many banks at once. Shared by
 * App\Livewire\Banks\BankIndex (every bank on /banks, batched into 2
 * queries total) and BankShow (standalone /banks/{bank}), and by the app
 * API's BankController — one query shape, not three copies of it.
 */
class BankAccountsGrouper
{
    /**
     * @param  \Illuminate\Support\Collection<int, int>  $bankIds
     * @return Collection<int, Collection> Keyed by bank_id, then account_number, then type.
     */
    public function forBankIds(Collection $bankIds): Collection
    {
        if ($bankIds->isEmpty()) {
            return collect();
        }

        // Grouped, NOT reduced to one representative account yet — a type
        // group can hold several historical (incl. soft-deleted) accounts,
        // and every one of them can have its own uncleared checks.
        $groupedByBank = BankAccount::withTrashed()
            ->whereIn('bank_id', $bankIds)
            ->get()
            ->groupBy('bank_id')
            ->map(fn (Collection $byBank) => $byBank
                ->groupBy('account_number')
                ->map(fn (Collection $byNumber) => $byNumber->groupBy('type')));

        $allAccountIds = $groupedByBank
            ->flatMap(fn (Collection $byNumber) => $byNumber->flatMap(
                fn (Collection $byType) => $byType->flatMap(fn (Collection $accounts) => $accounts)
            ))
            ->pluck('id');

        $checksByAccountId = $allAccountIds->isEmpty()
            ? collect()
            : Check::with(['user.vendors', 'vendor'])
                ->whereIn('bank_account_id', $allAccountIds)
                ->whereIn('check_type', ['Transfer', 'Check'])
                ->whereYear('date', '>=', 2024)
                ->whereDoesntHave('transactions')
                ->get()
                ->groupBy('bank_account_id');

        return $groupedByBank->map(fn (Collection $byNumber) => $byNumber->map(
            fn (Collection $byType) => $byType->map(function (Collection $accountsByType) use ($checksByAccountId) {
                return [
                    'account' => $accountsByType->sortByDesc('updated_at')->first(),
                    'checks' => $accountsByType->flatMap(
                        fn ($account) => $checksByAccountId->get($account->id, collect())
                    ),
                ];
            })
        ));
    }

    /**
     * The grouped accounts for one bank — same shape as forBankIds()->get($bank->id).
     *
     * @return Collection<string, Collection>
     */
    public function forBank(int $bankId): Collection
    {
        return $this->forBankIds(collect([$bankId]))->get($bankId, collect());
    }
}
