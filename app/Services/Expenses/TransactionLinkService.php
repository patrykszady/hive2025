<?php

namespace App\Services\Expenses;

use App\Models\Expense;
use App\Models\Transaction;
use Illuminate\Support\Collection;

/**
 * Manually linking an expense to a bank charge — extracted from
 * App\Livewire\Expenses\LinkTransaction so the Hive Contractors app's
 * TransactionLinkController writes exactly the same way. The automatic
 * matcher only pairs an expense with charges of the same amount from 7 days
 * before to 21 days after its date, so an invoice that differs from what was
 * charged never matches; this is the hand-fallback. The expense keeps its
 * own amount — only the link is added.
 */
class TransactionLinkService
{
    /** Days either side of the expense date to look for charges. */
    public const WINDOW_DAYS = 60;

    /** Most candidates shown. */
    public const LIMIT = 15;

    /**
     * Unlinked charges on the company's bank accounts (TransactionScope) near
     * the expense date: the expense's vendor first, then the closest amount,
     * then the closest date. The vendor's own charges show at any amount; a
     * charge with no vendor yet only within 5% (at least $5); another
     * vendor's charge only at the exact amount — a $63.62 Arlington Heights
     * CDJR charge was offered for a $60.48 Amazon order (expense 26499) —
     * mirrors LinkTransaction::candidates().
     *
     * @return Collection<int, Transaction>
     */
    public function candidates(Expense $expense): Collection
    {
        $amount = round((float) $expense->amount, 2);
        $date = $expense->date ?? now();
        $tolerance = max(5.0, abs($amount) * 0.05);

        return Transaction::query()
            ->with(['bank_account.bank', 'vendor' => fn ($query) => $query->withoutGlobalScopes()])
            ->whereNull('expense_id')
            ->whereNull('check_id')
            ->whereNull('deleted_at')
            ->whereBetween('transaction_date', [
                $date->copy()->subDays(self::WINDOW_DAYS)->toDateString(),
                $date->copy()->addDays(self::WINDOW_DAYS)->toDateString(),
            ])
            ->where('amount', $amount >= 0 ? '>' : '<', 0)
            ->where(function ($query) use ($expense, $amount, $tolerance) {
                if ($expense->vendor_id) {
                    $query->where('vendor_id', $expense->vendor_id);
                }

                $query->orWhere(fn ($unknown) => $unknown->whereNull('vendor_id')->whereBetween('amount', [$amount - $tolerance, $amount + $tolerance]))
                    ->orWhere('amount', $amount);
            })
            ->limit(100)
            ->get()
            ->sortBy(fn (Transaction $transaction) => [
                (int) ($transaction->vendor_id !== $expense->vendor_id),
                round(abs((float) $transaction->amount - $amount), 2),
                abs($transaction->transaction_date->diffInDays($date)),
            ])
            ->take(self::LIMIT)
            ->values();
    }

    /**
     * Links $transactionId to $expense — mirrors LinkTransaction::link().
     * Only ever a transaction TransactionScope already lets the person see
     * and that is not yet linked to any expense or check.
     */
    public function link(Expense $expense, int $transactionId): Transaction
    {
        $transaction = Transaction::query()
            ->whereKey($transactionId)
            ->whereNull('expense_id')
            ->whereNull('check_id')
            ->whereNull('deleted_at')
            ->first();

        abort_unless($transaction, 404);

        $transaction->expense_id = $expense->id;
        $transaction->manualExpenseLink = true;
        $transaction->save();
        $expense->searchable();

        return $transaction;
    }

    /**
     * Whether "Link bank transaction" should be offered for $expense at
     * all — mirrors the @if wrapping <livewire:expenses.link-transaction>
     * in resources/views/livewire/expenses/show.blade.php: no charge and no
     * check already settled it, and payments outside the bank (rebate checks,
     * store credit, points, cash, cards that aren't the company's) haven't
     * already covered the full amount.
     */
    public function canOffer(Expense $expense, ExpenseAssociationService $associations): bool
    {
        if ($expense->allTransactions()->isNotEmpty()) {
            return false;
        }

        if ($associations->linkedChecks($expense)->isNotEmpty()) {
            return false;
        }

        $offBankPayments = $expense->payments->filter(fn ($payment) => $payment->setRelation('expense', $expense)->paidOutsideBank());

        if ($offBankPayments->isEmpty()) {
            return true;
        }

        $paidOffBank = abs(round((float) $offBankPayments->sum('amount'), 2)) >= abs(round((float) $expense->amount, 2)) - 0.01;

        return ! $paidOffBank;
    }
}
