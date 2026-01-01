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
            'orderedReceipts',
            // Load distribution so Expense::project() withDefault can use the real name
            'distribution',
            // Load checks for many-to-many relationship with their bank accounts
            'checks.bank_account.bank',
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
        $allNotes = [];
        
        // Collect individual note parts from all receipts (uses orderedReceipts relationship)
        foreach ($this->expense->orderedReceipts as $receipt) {
            if (!empty($receipt->notes)) {
                // Split by ' | ' to get individual parts, then merge
                $parts = array_map('trim', explode(' | ', $receipt->notes));
                foreach ($parts as $part) {
                    if ($part !== '') {
                        $allNotes[] = $part;
                    }
                }
            }
        }
        
        // Fuzzy deduplicate notes to handle OCR variations
        $unique = $this->fuzzyDeduplicateNotes($allNotes);
        
        return !empty($unique) ? implode(', ', $unique) : null;
    }

    /**
     * Deduplicate notes using fuzzy matching to handle OCR errors.
     * Strings with >85% similarity are considered duplicates.
     */
    private function fuzzyDeduplicateNotes(array $notes): array
    {
        $unique = [];
        
        foreach ($notes as $note) {
            $isDuplicate = false;
            $noteLower = strtolower($note);
            
            foreach ($unique as $existing) {
                $existingLower = strtolower($existing);
                
                // Exact match
                if ($noteLower === $existingLower) {
                    $isDuplicate = true;
                    break;
                }
                
                // Fuzzy match using similar_text percentage
                similar_text($noteLower, $existingLower, $percent);
                if ($percent > 85) {
                    $isDuplicate = true;
                    break;
                }
            }
            
            if (!$isDuplicate) {
                $unique[] = $note;
            }
        }
        
        return $unique;
    }

    // Add this computed property
    #[Computed]
    public function hasReceiptLineItems()
    {
        return $this->expense->receipts
            ->filter(function($receipt) {
                return $receipt->receipt_items && is_array($receipt->receipt_items) && !empty($receipt->receipt_items['items'] ?? []);
            })
            ->isNotEmpty();
    }

    #[Computed]
    public function expenseAmount(): float
    {
        return (float) $this->expense->amount;
    }

    #[Computed]
    public function expenseMismatch(): bool
    {
        // Only consider mismatch when there is exactly one receipt with parsed line items
        if ($this->expense->receipts->count() !== 1) {
            return false;
        }

        $receipt = $this->expense->receipts->first();
        if (!$receipt || !$receipt->receipt_items || !is_array($receipt->receipt_items)) {
            return false;
        }

        // Compare totals as floats
        return (float) ($receipt->receipt_items['total'] ?? 0) !== (float) $this->expense->amount;
    }

    #[Title('Expense')]
    public function render()
    {
        $this->authorize('view', $this->expense);

        return view('livewire.expenses.show');
    }
}