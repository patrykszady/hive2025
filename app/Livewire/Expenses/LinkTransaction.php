<?php

namespace App\Livewire\Expenses;

use App\Models\Expense;
use App\Models\Transaction;
use App\Services\Expenses\TransactionLinkService;
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
 * added. The query/write logic lives in App\Services\Expenses\
 * TransactionLinkService, shared with the Hive Contractors app's
 * TransactionLinkController so both write identically.
 */
class LinkTransaction extends Component
{
    use AuthorizesRequests;

    /** Days either side of the expense date to look for charges. */
    public const WINDOW_DAYS = TransactionLinkService::WINDOW_DAYS;

    /** Most candidates shown. */
    public const LIMIT = TransactionLinkService::LIMIT;

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
     * then the closest date. The vendor's own charges at any amount, a charge
     * with no vendor yet within 5% (at least $5), another vendor's only at
     * the exact amount.
     *
     * @return Collection<int, Transaction>
     */
    #[Computed]
    public function candidates(): Collection
    {
        return app(TransactionLinkService::class)->candidates($this->expense);
    }

    public function link(int $transactionId): void
    {
        $this->authorize('update', $this->expense);

        app(TransactionLinkService::class)->link($this->expense, $transactionId);

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
