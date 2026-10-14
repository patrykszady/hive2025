<?php

namespace App\Services\Transactions;

use App\Models\BankAccount;
use App\Models\Distribution;
use App\Models\Expense;
use App\Models\Transaction;
use App\Models\Vendor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Pair the two legs of a credit-card payment — the payment received on the
 * card and the same amount leaving checking — and record them the way Hive
 * always has: a negative expense on the card leg, a positive one on the
 * checking leg (its parent the card's), both under the card's vendor
 * ("CapitalOne Credit") and the company's OFFICE distribution.
 *
 * Since the transfer rules of 2026-07-17 ("CAPITAL ONE MOBILE PMT" /
 * "CAPITAL ONE MOBILE PYMT") stamp both legs as transfers (check_number
 * 1010101) and clear their vendor, the old pairing — which looked only at
 * unstamped legs carrying the card's vendor — paired nothing after
 * 07/10/2026: 32 payments out of checking and 23 card credits sat unmatched.
 * Here a stamped leg pairs with a stamped account transfer of the same
 * amount (never a Zelle or Venmo to a person), the card's vendor comes from
 * that card's earlier pairs, and the link is marked deliberate so the
 * check-charge safeguard (which sees 1010101 as a check number) lets it be.
 */
class CardPaymentPairer
{
    /** The checking leg's payment date may be up to this many days after the card credit… */
    public const WINDOW_DAYS = 10;

    /** …or this many days before it (it often posts the day before the card shows it). */
    public const DAYS_BEFORE = 3;

    /**
     * Card payments Plaid removed are restored only from here on. Plaid
     * (Capital One) began removing them about two weeks after sending them
     * in July 2026; deletions before that include the 8338 → 4060 duplicate
     * cleanup, which must stay deleted.
     */
    public const RESTORE_FROM = '2026-07-01';

    /** A payment that came back to checking within this many days was never made. */
    public const RETURN_DAYS = 30;

    /** @return int pairs made */
    public function run(): int
    {
        $pairs = 0;
        $cardAccountsByCompany = BankAccount::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->whereIn('type', ['Credit', 'Credit Card'])
            ->get()
            ->groupBy('vendor_id');

        foreach ($cardAccountsByCompany as $companyId => $cardAccounts) {
            $company = Vendor::withoutGlobalScopes()->find($companyId);
            $office = Distribution::withoutGlobalScopes()->where('vendor_id', $companyId)->where('name', 'OFFICE')->first();
            if (! $company || ! $office) {
                continue;
            }
            $checkingIds = BankAccount::withoutGlobalScopes()->where('vendor_id', $companyId)->whereNull('deleted_at')->where('type', 'Checking')->pluck('id');
            if ($checkingIds->isEmpty()) {
                continue;
            }
            $cardVendors = $this->cardVendors($cardAccounts->pluck('id'));

            $credits = Transaction::withoutGlobalScopes()
                ->whereNull('deleted_at')
                ->whereNull('check_id')
                ->whereNull('expense_id')
                ->whereNull('deposit')
                ->where('amount', '<', 0)
                ->whereDate('transaction_date', '>=', '2022-10-07')
                ->whereIn('bank_account_id', $cardAccounts->pluck('id'))
                ->where(fn ($query) => $query
                    ->where(fn ($q) => $q->whereNull('check_number')->whereNotNull('vendor_id'))
                    ->orWhere('check_number', Transaction::TRANSFER_CHECK_NUMBER))
                ->orderBy('transaction_date')
                ->orderBy('id')
                ->get();

            foreach ($credits as $credit) {
                $vendorId = $credit->vendor_id ?: ($cardVendors['by_account'][$credit->bank_account_id] ?? $cardVendors['any']);
                if (! $vendorId) {
                    continue;
                }
                $debit = $this->checkingLeg($credit, (int) $vendorId, $checkingIds);
                if (! $debit) {
                    continue;
                }
                $this->pair($credit, $debit, (int) $vendorId, $company, $office);
                $pairs++;
            }

            $pairs += $this->pairRemovedCredits($cardAccounts->pluck('id'), $cardVendors, $checkingIds, $company, $office);
        }

        if ($pairs > 0) {
            Log::info('Card payments paired', ['pairs' => $pairs]);
        }

        return $pairs;
    }

    /**
     * Card payments Plaid removed after sending them, restored and paired
     * with the money that left checking for them. Plaid (Capital One)
     * removed 10 card payments about two weeks after sending them, July to
     * September 2026, and never sent them again, while the checking side of
     * each stayed: 8 of them, $22,850, sat on the 2025-26 audit's Missing
     * Expenses with no card side to pair. Only a payment still unpaired after
     * the live card credits had their turn takes one, and only when the money
     * did not come back to checking.
     */
    protected function pairRemovedCredits(Collection $cardAccountIds, array $cardVendors, Collection $checkingIds, Vendor $company, Distribution $office): int
    {
        $pairs = 0;
        $removed = Transaction::withoutGlobalScopes()
            ->whereNotNull('deleted_at')
            ->whereNull('check_id')
            ->whereNull('expense_id')
            ->whereNull('deposit')
            ->where('amount', '<', 0)
            ->whereDate('transaction_date', '>=', self::RESTORE_FROM)
            ->whereIn('bank_account_id', $cardAccountIds)
            ->where(fn ($query) => $query
                ->where(fn ($q) => $q->whereNull('check_number')->whereNotNull('vendor_id'))
                ->orWhere('check_number', Transaction::TRANSFER_CHECK_NUMBER))
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();

        foreach ($removed as $credit) {
            $vendorId = $credit->vendor_id ?: ($cardVendors['by_account'][$credit->bank_account_id] ?? $cardVendors['any']);
            if (! $vendorId) {
                continue;
            }
            $debit = $this->checkingLeg($credit, (int) $vendorId, $checkingIds);
            if (! $debit || $this->cameBack($debit)) {
                continue;
            }

            DB::transaction(function () use ($credit, $debit, $vendorId, $company, $office) {
                $credit->restore();
                $this->pair($credit, $debit, (int) $vendorId, $company, $office);
            });
            Log::info('Card payment Plaid had removed was restored and paired', [
                'card_transaction_id' => $credit->id,
                'checking_transaction_id' => $debit->id,
                'amount' => (float) $debit->amount,
            ]);
            $pairs++;
        }

        return $pairs;
    }

    /** The payment came back: the same amount returned to that checking account soon after. */
    protected function cameBack(Transaction $debit): bool
    {
        return Transaction::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('bank_account_id', $debit->bank_account_id)
            ->where('amount', -abs((float) $debit->amount))
            ->whereBetween('transaction_date', [$debit->transaction_date->toDateString(), $debit->transaction_date->copy()->addDays(self::RETURN_DAYS)->toDateString()])
            ->where(fn ($q) => $q->where('plaid_merchant_description', 'like', '%CAPITAL ONE%')
                ->orWhere('plaid_merchant_description', 'like', '%RETURN%')
                ->orWhere('plaid_merchant_description', 'like', '%REVERS%'))
            ->exists();
    }

    /**
     * The card's vendor, from the legs it was paired with before: per card
     * account, and the most common across all of them as the fallback.
     *
     * @param  Collection<int, int>  $cardAccountIds
     * @return array{by_account: array<int, int>, any: int|null}
     */
    protected function cardVendors(Collection $cardAccountIds): array
    {
        $rows = Transaction::withoutGlobalScopes()
            ->whereIn('bank_account_id', $cardAccountIds)
            ->whereNotNull('expense_id')
            ->whereNotNull('vendor_id')
            ->where('amount', '<', 0)
            ->selectRaw('bank_account_id, vendor_id, count(*) as n')
            ->groupBy('bank_account_id', 'vendor_id')
            ->get();

        $byAccount = $rows->groupBy('bank_account_id')->map(fn ($g) => (int) $g->sortByDesc('n')->first()->vendor_id)->all();
        $any = $rows->groupBy('vendor_id')->map(fn ($g) => $g->sum('n'))->sortDesc()->keys()->first();

        return ['by_account' => $byAccount, 'any' => $any ? (int) $any : null];
    }

    /** The checking leg of a card credit, closest by payment date, or null. */
    protected function checkingLeg(Transaction $credit, int $vendorId, Collection $checkingIds): ?Transaction
    {
        $creditDay = $credit->transaction_date->copy()->startOfDay();

        return Transaction::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->whereNull('expense_id')
            ->whereNull('check_id')
            ->whereIn('bank_account_id', $checkingIds)
            ->where('amount', abs((float) $credit->amount))
            // Wide in SQL (the payment date in the description can sit a few
            // days off the posting date); exact below.
            ->whereBetween('transaction_date', [$creditDay->copy()->subDays(self::DAYS_BEFORE + 7)->toDateString(), $creditDay->copy()->addDays(self::WINDOW_DAYS + 7)->toDateString()])
            ->get()
            // The same card vendor (the old, unstamped way), or a stamped
            // transfer between our own accounts — never a payment to a person.
            ->filter(fn (Transaction $t) => (int) $t->vendor_id === $vendorId
                || ($t->vendor_id === null && $t->check_number === Transaction::TRANSFER_CHECK_NUMBER && $t->transferKind() === 'account'))
            ->filter(function (Transaction $t) use ($creditDay) {
                $offset = $creditDay->diffInDays(self::paymentDate($t), false);

                return $offset >= -self::DAYS_BEFORE && $offset <= self::WINDOW_DAYS;
            })
            ->sortBy(fn (Transaction $t) => [abs($creditDay->diffInDays(self::paymentDate($t), false)), $t->id])
            ->first();
    }

    /**
     * The day a checking leg paid the card: the date in its description when
     * the bank prints one ("CAPITAL ONE MOBILE PMT082126CA…" is 08/21/26) and
     * it sits within a week of the posting date, else the posting date.
     */
    public static function paymentDate(Transaction $debit): \Illuminate\Support\Carbon
    {
        $posted = $debit->transaction_date->copy()->startOfDay();
        if (preg_match('/\bPMT(\d{2})(\d{2})(\d{2})/', (string) $debit->plaid_merchant_description, $m) && checkdate((int) $m[1], (int) $m[2], 2000 + (int) $m[3])) {
            $printed = \Illuminate\Support\Carbon::create(2000 + (int) $m[3], (int) $m[1], (int) $m[2])->startOfDay();
            if (abs($posted->diffInDays($printed, false)) <= 7) {
                return $printed;
            }
        }

        return $posted;
    }

    protected function pair(Transaction $credit, Transaction $debit, int $vendorId, Vendor $company, Distribution $office): void
    {
        DB::transaction(function () use ($credit, $debit, $vendorId, $company, $office) {
            $creditExpense = Expense::create([
                'distribution_id' => $office->id,
                'created_by_user_id' => 0,
                'amount' => $credit->amount,
                'date' => $credit->transaction_date,
                'vendor_id' => $vendorId,
                'belongs_to_vendor_id' => $company->id,
            ]);
            $credit->vendor_id ??= $vendorId;
            $credit->manualExpenseLink = true;
            $credit->expense()->associate($creditExpense);
            $credit->save();

            $debitExpense = Expense::create([
                'distribution_id' => $office->id,
                'created_by_user_id' => 0,
                'amount' => $debit->amount,
                'date' => $debit->transaction_date,
                'vendor_id' => $vendorId,
                'belongs_to_vendor_id' => $company->id,
                'parent_expense_id' => $creditExpense->id,
            ]);
            $debit->vendor_id ??= $vendorId;
            $debit->manualExpenseLink = true;
            $debit->expense()->associate($debitExpense);
            $debit->save();
        });
    }
}
