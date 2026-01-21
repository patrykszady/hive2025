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

        $excludedIds = $expense->associated()->pluck('id')->toArray();
        $excludedIds[] = $expense->id;

        $this->expenses = Expense::query()
            // No eager loading of vendor here since we compare the ID directly.
            ->whereNotIn('id', $excludedIds)
            ->where(function ($query) use ($expense) {
                $query->where(function ($q) use ($expense) {
                        // Condition A: amount matches and date is within ±3 months.
                        $q->where(function ($amountQuery) use ($expense) {
                                $amountQuery->where('amount', $expense->amount)
                                            ->orWhere('amount', -1 * $expense->amount);
                            })
                          ->whereBetween('date', [
                              $expense->date->copy()->subMonths(3),
                              $expense->date->copy()->addMonths(3),
                          ]);
                    })
                    ->orWhere(function ($q) use ($expense) {
                        // Condition B: same calendar date and same vendor.
                        $q->whereDate('date', $expense->date->toDateString())
                          ->where('vendor_id', $expense->vendor_id);
                    });
            })
            ->orderBy('date', 'desc')
            ->get();

        $this->modal('associated_expenses_form_modal')->show();
    }

    public function save()
    {
        $associate = Expense::findOrFail($this->associate_expense);
        $current = $this->expense->fresh();

        $associateRootId = $associate->parent_expense_id ?: $associate->id;
        $currentRootId = $current->parent_expense_id ?: $current->id;

        if ($associateRootId !== $currentRootId) {
            $currentGroupIds = Expense::query()
                ->where(function ($query) use ($currentRootId) {
                    $query->where('id', $currentRootId)
                        ->orWhere('parent_expense_id', $currentRootId);
                })
                ->pluck('id');

            if ($currentGroupIds->isNotEmpty()) {
                Expense::query()
                    ->whereIn('id', $currentGroupIds)
                    ->where('id', '!=', $associateRootId)
                    ->update(['parent_expense_id' => $associateRootId]);
            }
        } elseif ($current->id !== $associateRootId) {
            $current->parent_expense_id = $associateRootId;
            $current->save();
        }

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
