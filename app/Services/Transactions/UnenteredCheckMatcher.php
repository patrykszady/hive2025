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
 *
 * A deposit by card and the balance by check never add up to the invoice to
 * the cent: Climate Solutions invoice 68297 (expense 28924, $27,618.69) was
 * paid with a $14,420.00 card charge the bank already tags as the vendor
 * (a $14,000 deposit plus the 3% card fee the invoice bills as "Misc.
 * Charges") and check 2682 for $13,295.32, two days after the invoice — the
 * balance it covers is $13,198.69. So when no set of checks adds up, the
 * expense is paid by ONE charge tagged with its vendor (the deposit, at
 * least DEPOSIT_SHARE of the invoice) plus ONE check clearing around the
 * invoice date within BALANCE_TOLERANCE of what is left — and only when
 * exactly one such pair exists.
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

    /** A deposit is at least this share of the invoice. */
    public const DEPOSIT_SHARE = 0.2;

    /** The balance check may differ from what is left by this share of it (fees, rounding). */
    public const BALANCE_TOLERANCE = 0.01;

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

                if ($combination !== null) {
                    $this->payWithChecks($expense, $combination);
                    $paid++;

                    continue;
                }

                $pair = $this->onlyDepositAndBalance($expense, $charges, $bankAccountIds->all());

                if ($pair !== null) {
                    [$deposit, $balance] = $pair;
                    $this->payWithDepositAndCheck($expense, $deposit, $balance);
                    $paid++;
                }
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
     * The one deposit (an unclaimed charge the bank tagged with the expense's
     * vendor) and the one check that pays what is left, or null when there
     * is none or more than one such pair.
     *
     * @param  Collection<int, Transaction>  $checkCharges  unclaimed paper-check charges (chargesFor())
     * @param  array<int, int>  $bankAccountIds
     * @return array{0: Transaction, 1: Transaction}|null
     */
    private function onlyDepositAndBalance(Expense $expense, Collection $checkCharges, array $bankAccountIds): ?array
    {
        $total = (int) round((float) $expense->amount * 100);
        $date = Carbon::parse($expense->date)->startOfDay();
        $lastFrom = $date->copy()->subDays(self::LAST_CHECK_DAYS_BEFORE);
        $lastTo = $date->copy()->addDays(self::LAST_CHECK_DAYS_AFTER)->endOfDay();

        $deposits = Transaction::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->whereIn('bank_account_id', $bankAccountIds)
            ->where('vendor_id', $expense->vendor_id)
            ->whereNull('check_id')
            ->whereNull('expense_id')
            ->where(fn ($query) => $query->whereNull('deposit')->orWhere('deposit', false))
            ->where('amount', '>=', round($total * self::DEPOSIT_SHARE / 100, 2))
            ->where('amount', '<', $expense->amount)
            ->whereBetween('transaction_date', [
                $date->copy()->subDays(self::DEPOSIT_DAYS_BEFORE)->toDateString(),
                $lastTo->toDateTimeString(),
            ])
            ->whereDoesntHave('expenses')
            ->get();

        $pairs = [];
        foreach ($deposits as $deposit) {
            $left = $total - (int) round((float) $deposit->amount * 100);
            $tolerance = (int) ceil($left * self::BALANCE_TOLERANCE);

            foreach ($checkCharges as $check) {
                $clears = Carbon::parse($check->transaction_date);
                $cents = (int) round((float) $check->amount * 100);

                if ($clears->lt($lastFrom) || $clears->gt($lastTo) || abs($cents - $left) > $tolerance) {
                    continue;
                }

                $pairs[] = [$deposit, $check];

                if (count($pairs) > 1) {
                    return null;
                }
            }
        }

        return $pairs[0] ?? null;
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

    private function payWithDepositAndCheck(Expense $expense, Transaction $deposit, Transaction $balance): void
    {
        $deposit->expense_id = $expense->id;
        $deposit->save();

        Log::channel('add_check_id_to_transactions')->info('Paid an expense with a vendor deposit and a balance check', [
            'expense_id' => $expense->id,
            'deposit_transaction_id' => $deposit->id,
            'deposit' => $deposit->amount,
            'check_number' => $balance->check_number,
            'check' => $balance->amount,
            'amount' => $expense->amount,
        ]);

        $this->payWithChecks($expense, [$balance]);
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
