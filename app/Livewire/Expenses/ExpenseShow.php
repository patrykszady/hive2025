<?php

namespace App\Livewire\Expenses;

use App\Models\Expense;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Title;
use Livewire\Component;

class ExpenseShow extends Component
{
    use AuthorizesRequests;

    public Expense $expense;

    protected $listeners = ['refreshComponent' => '$refresh'];

    #[Title('Expense')]
    public function render()
    {
        $this->authorize('view', $this->expense);

        return view('livewire.expenses.show');
    }
}
