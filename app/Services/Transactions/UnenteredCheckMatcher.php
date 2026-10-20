<?php

namespace App\Services\Transactions;

use App\Models\Check;
use App\Models\Expense;
use App\Models\ExpensePayment;
use App\Models\Transaction;
use App\Models\Vendor;
use App\Support\CheckCharge;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Pays an expense with paper checks nobody entered in Hive. A supplier paid
 * by check — a deposit when ordering, the balance at pickup — leaves only
 * "CHECK 2662" lines on the bank statement. add_check_id_to_transactions()
 * links a charge only to a check someone entered, and matchChecksToExpenses()
 * only links entered checks to expenses, so Climate Solutions invoice 68405
 * (expense 28901, $14,742.50) sat beside checks 2662 ($8,000, 09/18) and 2683
 * ($6,742.50, 10/16) with nothing tying them together (2026-10-20).
 *
 * Here the check is written from its bank line: when one, two or three
 * unclaimed check charges add up exactly to an unpaid supplier expense — the
 * last of them clearing around the invoice date, earlier ones up to
 * DEPOSIT_DAYS_BEFORE ahead of it — and no other set of charges does, each
 * charge becomes a Check for that vendor, linked to its charge and to the
 * expense (check_expense, like matchChecksToExpenses()). Subcontractors are
 * left alone: their checks are written in Hive and linked from there.
 */
class UnenteredCheckMatcher
{
    /** How far ahead of the invoice a deposit check may clear. */
    public const DEPOSIT_DAYS_BEFORE = 120;

    /** The last check (the balance, or the only check) clears this close before the invoice… */
    public const LAST_CHECK_DAYS_BEFORE = 7;

    /** …or up to this long after it. */
    public const LAST_CHECK_DAYS_AFTER = 30;

    /** A deposit, a progress payment and the balance. */
    public const MAX_CHECKS = 3;

    /** Expenses looked at per company each run, newest first. */
    private const EXPENSES_PER_RUN = 200;

    /** Placeholder check numbers for Transfer and Cash payments. */
    private const PLACEHOLDER_NUMBERS = ['1010101', '2020202'];

    /**
     * @return int the number of expenses paid
     */
    public function run(): int
    {
        $paid = 0;

        foreach (Vendor::hiveVendors()->get() as $company) {
            $bankAccountIds = $company->bank_accounts()->pluck('id');

            if ($bankAccountIds->isEmpty()) {
                continue;
            }

            foreach ($this->unpaidSupplierExpenses($company) as $expense) {
                $charges = $this->chargesFor($expense, $bankAccountIds->all());
                $combination = $this->onlyCombination($expense, $charges);

                if ($combination === null) {
                    continue;
                }

                $this->payWithChecks($expense, $combination);
                $paid++;
            }
        }

        return $paid;
    }

    /**
     * Supplier expenses with nothing paying them yet: no check, no bank
     * charge, not a reimbursement and not paid by card per the receipt.
     *
     * @return Collection<int, Expense>
     */
    private function unpaidSupplierExpenses(Vendor $company): Collection
    {
        return Expense::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('belongs_to_vendor_id', $company->id)
            ->whereNotNull('vendor_id')
            ->where('vendor_id', '!=', $company->id)
            ->whereNull('paid_by')
            ->whereNull('check_id')
            ->where('amount', '>', 0)
            ->whereDate('date', '>=', now()->subMonths(6))
            ->whereDoesntHave('checks')
            ->whereDoesntHave('transactions')
            ->whereDoesntHave('payments', fn ($query) => $query->where('method', ExpensePayment::CARD))
            ->whereHas('vendor', fn ($query) => $query->withoutGlobalScopes()->where(fn ($q) => $q->whereNull('business_type')->orWhere('business_type', '!=', 'Sub')))
            ->orderByDesc('date')
            ->limit(self::EXPENSES_PER_RUN)
            ->get();
    }

    /**
     * Unclaimed paper-check charges that could be part of paying $expense.
     *
     * @param  array<int, int>  $bankAccountIds
     * @return Collection<int, Transaction>
     */
    private function chargesFor(Expense $expense, array $bankAccountIds): Collection
    {
        $date = Carbon::parse($expense->date)->startOfDay();

        return Transaction::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->whereIn('bank_account_id', $bankAccountIds)
            ->whereNull('check_id')
            ->whereNull('expense_id')
            ->whereNotNull('check_number')
            ->whereNotIn('check_number', self::PLACEHOLDER_NUMBERS)
            ->where(fn ($query) => $query->whereNull('deposit')->orWhere('deposit', false))
            ->where('amount', '>', 0)
            ->where('amount', '<=', $expense->amount)
            ->whereBetween('transaction_date', [
                $date->copy()->subDays(self::DEPOSIT_DAYS_BEFORE)->toDateString(),
                $date->copy()->addDays(self::LAST_CHECK_DAYS_AFTER)->toDateString().' 23:59:59',
            ])
            ->where(fn ($query) => $query->whereNull('plaid_merchant_description')->orWhere('plaid_merchant_description', 'not like', '%RETURN%'))
            ->orderBy('transaction_date')
            ->get()
            // A real check number: "INCLEARING CHECK … 0000" names no check.
            ->filter(fn (Transaction $charge) => CheckCharge::isCheck($charge->check_number, null) && (int) $charge->check_number > 0)
            // Already entered in Hive: add_check_id_to_transactions() links it to that check.
            ->reject(fn (Transaction $charge) => Check::withoutGlobalScopes()
                ->whereNull('deleted_at')
                ->where('check_type', 'Check')
                ->where('bank_account_id', $charge->bank_account_id)
                ->where('check_number', $charge->check_number)
                ->exists())
            ->values();
    }

    /**
     * The one set of charges adding up to the expense whose last check clears
     * around the invoice date, or null when there is none or more than one.
     * A round-hundreds amount is only matched by a single check: split across
     * checks, sums like that are too easy to hit by chance.
     *
     * @param  Collection<int, Transaction>  $charges
     * @return list<Transaction>|null
     */
    private function onlyCombination(Expense $expense, Collection $charges): ?array
    {
        if ($charges->isEmpty()) {
            return null;
        }

        $target = (int) round((float) $expense->amount * 100);
        $date = Carbon::parse($expense->date)->startOfDay();
        $lastFrom = $date->copy()->subDays(self::LAST_CHECK_DAYS_BEFORE);
        $lastTo = $date->copy()->addDays(self::LAST_CHECK_DAYS_AFTER)->endOfDay();
        $maxChecks = $target % 10000 === 0 ? 1 : self::MAX_CHECKS;

        $cents = $charges->mapWithKeys(fn (Transaction $charge) => [$charge->id => (int) round((float) $charge->amount * 100)])->all();
        $byId = $charges->keyBy('id');
        $found = [];

        foreach ($charges as $last) {
            $lastDate = Carbon::parse($last->transaction_date);

            if ($lastDate->lt($lastFrom) || $lastDate->gt($lastTo)) {
                continue;
            }

            // The others clear no later than the last one.
            $earlier = $charges->filter(fn (Transaction $other) => $other->id !== $last->id
                && Carbon::parse($other->transaction_date)->lte($lastDate))->pluck('id')->all();

            foreach ($this->subsetsSummingTo($target - $cents[$last->id], $earlier, $cents, $maxChecks - 1) as $subset) {
                $ids = array_merge($subset, [$last->id]);
                sort($ids);
                $found[implode(',', $ids)] = $ids;
            }

            if (count($found) > 1) {
                return null;
            }
        }

        if (count($found) !== 1) {
            return null;
        }

        return array_map(fn (int $id) => $byId[$id], array_values($found)[0]);
    }

    /**
     * Sets of up to $size ids from $ids whose cents add up to $remaining.
     *
     * @param  list<int>  $ids
     * @param  array<int, int>  $cents
     * @return list<list<int>>
     */
    private function subsetsSummingTo(int $remaining, array $ids, array $cents, int $size, int $from = 0): array
    {
        if ($remaining === 0) {
            return [[]];
        }

        if ($remaining < 0 || $size === 0) {
            return [];
        }

        $subsets = [];

        for ($i = $from, $n = count($ids); $i < $n; $i++) {
            foreach ($this->subsetsSummingTo($remaining - $cents[$ids[$i]], $ids, $cents, $size - 1, $i + 1) as $rest) {
                $subsets[] = array_merge([$ids[$i]], $rest);
            }
        }

        return $subsets;
    }

    /**
     * @param  list<Transaction>  $charges
     */
    private function payWithChecks(Expense $expense, array $charges): void
    {
        $checkIds = [];

        foreach ($charges as $charge) {
            $check = Check::create([
                'check_type' => 'Check',
                'check_number' => $charge->check_number,
                'date' => Carbon::parse($charge->transaction_date)->toDateString(),
                'amount' => $charge->amount,
                'bank_account_id' => $charge->bank_account_id,
                'vendor_id' => $expense->vendor_id,
                'belongs_to_vendor_id' => $expense->belongs_to_vendor_id,
                'created_by_user_id' => 0,
            ]);

            $charge->check_id = $check->id;
            $charge->save();

            $checkIds[] = $check->id;
        }

        // Each check keeps its own amount: never recalculateAmount() here,
        // which would set every one of them to the whole expense.
        $expense->checks()->attach($checkIds);
        $expense->searchable();

        Log::channel('add_check_id_to_transactions')->info('Paid an expense with checks written from their bank lines', [
            'expense_id' => $expense->id,
            'check_ids' => $checkIds,
            'check_numbers' => array_map(fn (Transaction $charge) => $charge->check_number, $charges),
            'amount' => $expense->amount,
        ]);
    }
}
