<?php

namespace App\Livewire\Expenses;

use App\Models\Expense;
use Flux;
use Livewire\Component;

class ExpensesAssociated extends Component
{
    public Expense $expense;

    public $associate_expense = '';

    public $expenses = [];

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
        // associated_expenses
        $this->expenses =
            Expense::search($expense->amount)
                ->orderBy('date', 'desc')
                ->get()
                ->whereBetween('date', [$expense->date->subMonths(3), $expense->date->addMonths(3)])
                ->whereNotIn('id', array_merge(! $expense->associated->isEmpty() ? $expense->associated_expenses->pluck('id')->toArray() : [], [$expense->id]));

        $this->modal('associated_expenses_form_modal')->show();
    }

    public function save()
    {
        $this->expense->parent_expense_id = $this->associate_expense;
        $this->expense->save();

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
