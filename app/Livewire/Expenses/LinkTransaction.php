<?php

namespace App\Livewire\Expenses;

use App\Models\Expense;
use App\Models\Transaction;
use App\Services\Expenses\OutsideCardService;
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

    /**
     * Cards the receipt says paid this expense that may be someone else's:
     * not marked yet, and never seen on one of the company's bank charges.
     *
     * @return list<string>
     */
    #[Computed]
    public function outsideCardCandidates(): array
    {
        return app(OutsideCardService::class)->candidates($this->expense);
    }

    /**
     * The card isn't the company's (card ••1854 paid two Amazon orders and
     * is on none of GS Construction's accounts): its payments count as paid
     * on every expense it paid, and the matcher stops looking for a charge.
     */
    public function markOutsideCard(string $lastFour): void
    {
        $this->authorize('update', $this->expense);

        abort_unless(in_array($lastFour, $this->outsideCardCandidates, true), 404);

        app(OutsideCardService::class)->mark((int) $this->expense->belongs_to_vendor_id, $lastFour, auth()->id());

        Flux::toast(
            text: 'Every expense paid with card ••'.$lastFour.' now counts as paid outside the company\'s bank.',
            heading: \App\Models\OutsideCard::labelFor((int) $this->expense->belongs_to_vendor_id),
            variant: 'success',
        );

        $this->redirect(route('expenses.show', $this->expense), navigate: true);
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
