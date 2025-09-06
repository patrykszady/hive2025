<?php

namespace App\Livewire\Expenses;

use App\Models\Expense;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Title;
use Livewire\Attributes\Computed;
use Livewire\Component;

class ExpenseShow extends Component
{
    use AuthorizesRequests;

    public Expense $expense;
    public $selectedSplitId = null;

    protected $listeners = ['refreshComponent' => '$refresh'];

    public function mount(Expense $expense)
    {
        $this->authorize('view', $expense);
        $this->expense = $expense;
        
        // Eager load with ordered receipts
        $this->expense->load([
            'receipts' => function($query) {
                $query->ordered();
            },
            // Load distribution so Expense::project() withDefault can use the real name
            'distribution',
        ]);

    }
    
    // Manual toggle method for checkbox selection
    public function toggleSplit($id)
    {
        // If already selected, deselect it. Otherwise select the new one
        $this->selectedSplitId = ($this->selectedSplitId == $id) ? null : $id;
    }

    // Get the currently selected split
    #[Computed]
    public function selectedSplit()
    {
        if (!$this->selectedSplitId) {
            return null;
        }
        
        return $this->expense->splits->firstWhere('id', $this->selectedSplitId);
    }

    #[Computed]
    public function notesSummary()
    {
        $notes = [];
        
        // Add the expense note if it exists
        // if($this->expense->note) {
        //     $notes[] = $this->expense->note;
        // }
        
        // Add notes from all receipts
        if($this->expense->receipts()->exists()) {
            foreach($this->expense->receipts as $receipt) {
                if(!empty($receipt->notes)) {
                    $notes[] = $receipt->notes;
                }
            }
        }
        
        return !empty($notes) ? implode(', ', $notes) : null;
    }

    // Add this computed property
    #[Computed]
    public function hasReceiptLineItems()
    {
        return $this->expense->receipts
            ->filter(function($receipt) {
                return $receipt->receipt_items && !empty($receipt->receipt_items->items);
            })
            ->isNotEmpty();
    }

    #[Title('Expense')]
    public function render()
    {
        $this->authorize('view', $this->expense);

        return view('livewire.expenses.show');
    }
}