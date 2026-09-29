<?php

namespace App\Livewire\Expenses;

use App\Models\Expense;
use App\Models\Transaction;
use Flux\Flux;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Links an expense to a bank charge by hand. The automatic matcher only pairs
 * an expense with charges of the same amount from 7 days before to 21 days
 * after its date, so an invoice that differs from what was charged (expense
 * 28594: a $498.03 Schwake Stone invoice dated 09/24 against a $497.23 charge
 * on 08/19) never matches. The expense keeps its own amount; only the link is
 * added.
 */
class LinkTransaction extends Component
{
    use AuthorizesRequests;

    /** Days either side of the expense date to look for charges. */
    public const WINDOW_DAYS = 60;

    /** Most candidates shown. */
    public const LIMIT = 15;

    #[Locked]
    public Expense $expense;

    /** The candidate list is only queried once someone opens it. */
    public bool $open = false;

    public function openPicker(): void
    {
        $this->authorize('update', $this->expense);

        $this->open = true;
        $this->modal('link-transaction')->show();
    }

    /**
     * Unlinked charges on the company's bank accounts (TransactionScope) near
     * the expense date: the expense's vendor first, then the closest amount,
     * then the closest date. Other vendors' charges only appear when the
     * amount is within 5% (at least $5), so the list stays short.
     *
     * @return Collection<int, Transaction>
     */
    #[Computed]
    public function candidates(): Collection
    {
        $expense = $this->expense;
        $amount = (float) $expense->amount;
        $date = $expense->date ?? now();
        $tolerance = max(5.0, abs($amount) * 0.05);

        return Transaction::query()
            ->with('bank_account.bank')
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

                $query->orWhereBetween('amount', [$amount - $tolerance, $amount + $tolerance]);
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

    public function link(int $transactionId): void
    {
        $this->authorize('update', $this->expense);

        // TransactionScope limits this to the company's own bank accounts.
        $transaction = Transaction::query()
            ->whereKey($transactionId)
            ->whereNull('expense_id')
            ->whereNull('check_id')
            ->whereNull('deleted_at')
            ->first();

        abort_unless($transaction, 404);

        $transaction->expense_id = $this->expense->id;
        $transaction->manualExpenseLink = true;
        $transaction->save();
        $this->expense->searchable();

        Flux::toast(
            text: 'The bank charge is linked. The expense keeps its own amount.',
            heading: 'Transaction linked',
            variant: 'success',
        );

        $this->redirect(route('expenses.show', $this->expense), navigate: true);
    }

    public function render()
    {
        return view('livewire.expenses.link-transaction');
    }
}
