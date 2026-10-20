<?php

namespace App\Livewire\Expenses;

use App\Models\Expense;
use App\Services\Expenses\ExpenseAssociationService;
use Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

class ExpensesAssociated extends Component
{
    /** Most expenses a search shows. */
    public const SEARCH_LIMIT = 30;

    /** Most suggestions shown (newest first); a vendor like Home Depot has thousands of expenses. */
    public const SUGGESTION_LIMIT = 40;

    public Expense $expense;

    public $associate_expense = '';

    /** Suggested matches: the same vendor (newest first), or the same amount within three months. */
    public $expenses = [];

    /**
     * Anything else: an id, an amount, a vendor or an invoice. The suggestions
     * alone could not link expense 28701 ($1,500, GS Construction, 10/07) to
     * 28703 (-$44.99, Sixt, 10/06) — neither rule fits, so neither ever showed
     * in the other's list (Patryk, 2026-10-11).
     */
    public string $search = '';

    protected $listeners = ['addAssociatedExpense'];

    public function rules()
    {
        return [
            'associate_expense' => 'required',
        ];
    }

    public function addAssociatedExpense(Expense $expense)
    {
        $this->expense = $expense;
        $this->search = '';
        $this->associate_expense = '';

        // The suggestion query lives in ExpenseAssociationService, shared
        // with the Hive Contractors app's ExpenseAssociationController.
        $this->expenses = app(ExpenseAssociationService::class)->suggestions($expense);

        $this->modal('associated_expenses_form_modal')->show();
    }

    /**
     * What the picker lists: the suggestions, or — once something is typed —
     * every expense matching it, newest first. A number matches an id or an
     * amount (either sign); text matches the vendor's name or the invoice.
     *
     * @return Collection<int, Expense>
     */
    #[Computed]
    public function candidates(): Collection
    {
        $term = trim($this->search);

        if ($term === '') {
            return collect($this->expenses);
        }

        return app(ExpenseAssociationService::class)->search($this->expense, $term);
    }

    public function save()
    {
        $associate = Expense::findOrFail($this->associate_expense);

        app(ExpenseAssociationService::class)->associate($this->expense, $associate);

        $this->dispatch('refreshComponent')->to('expenses.expense-show');
        $this->modal('associated_expenses_form_modal')->close();

        Flux::toast(
            duration: 5000,
            position: 'top right',
            variant: 'success',
            heading: 'Expenses Associated',
            // route / href / wire:click
            text: '',
        );
    }

    public function render()
    {
        return view('livewire.expenses.associated');
    }
}
