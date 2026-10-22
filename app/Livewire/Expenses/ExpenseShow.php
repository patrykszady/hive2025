<?php

namespace App\Livewire\Expenses;

use App\Models\Check;
use App\Models\Expense;
use App\Models\ExpenseReceipts;
use App\Services\Expenses\ExpenseAssociationService;
use App\Services\Expenses\OutsideCardService;
use App\Services\Expenses\ReceiptLineItemDetail;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Title;
use Livewire\Attributes\Computed;
use Livewire\Component;

class ExpenseShow extends Component
{
    use AuthorizesRequests;

    public Expense $expense;
    public $selectedSplitId = null;
    public bool $showItemModal = false;
    public ?array $selectedItem = null;

    protected $listeners = ['refreshComponent' => '$refresh'];

    /**
     * Receipts grouped for display: one entry per primary (non-supplement)
     * receipt, newest first, each carrying its notes-only supplement scans.
     * Supplements never get their own tab — their file rides as an extra
     * view icon on the primary and only their handwritten notes are shown.
     * A supplement whose primary row is gone rides with the first group so
     * its file stays reachable; if only supplements exist they act as
     * primaries.
     *
     * @return \Illuminate\Support\Collection<int, array{receipt: ExpenseReceipts, supplements: \Illuminate\Support\Collection<int, ExpenseReceipts>}>
     */
    #[Computed]
    public function receiptGroups(): \Illuminate\Support\Collection
    {
        $receipts = $this->expense->receipts;
        $primaries = $receipts->reject(fn (ExpenseReceipts $receipt) => $receipt->isSupplement())
            ->sortByDesc('id')
            ->values();

        if ($primaries->isEmpty()) {
            return $receipts->sortByDesc('id')->values()
                ->map(fn (ExpenseReceipts $receipt) => ['receipt' => $receipt, 'supplements' => collect()]);
        }

        $primaryIds = $primaries->pluck('id')->all();
        $supplementsByPrimary = $receipts
            ->filter(fn (ExpenseReceipts $receipt) => $receipt->isSupplement())
            ->groupBy(function (ExpenseReceipts $receipt) use ($primaryIds, $primaries) {
                $primaryId = (int) (($receipt->receipt_items ?? [])['supplement_of_receipt_id'] ?? 0);

                return in_array($primaryId, $primaryIds, true) ? $primaryId : $primaries->first()->id;
            });

        return $primaries->map(fn (ExpenseReceipts $receipt) => [
            'receipt' => $receipt,
            'supplements' => $supplementsByPrimary->get($receipt->id, collect())->sortByDesc('id')->values(),
        ]);
    }

    public function mount(Expense $expense)
    {
        $this->authorize('view', $expense);
        $this->expense = $expense;
        
        // Eager load with ordered receipts
        $this->expense->load([
            'orderedReceipts',
            'receipts',
            'vendor',
            // Load distribution so Expense::project() withDefault can use the real name
            'distribution',
            // Load checks for many-to-many relationship with their bank accounts
            'checks.bank_account.bank',
        ]);

        // The receipt card reads $receipt->expense->vendor — hand each
        // receipt its parent so the back-reference never lazy-loads.
        $this->expense->receipts->each->setRelation('expense', $this->expense);
        $this->expense->orderedReceipts->each->setRelation('expense', $this->expense);
    }

    public function removeFromCheck(int $checkId): void
    {
        $this->authorize('update', $this->expense);

        $check = Check::findOrFail($checkId);

        // Extracted to ExpenseAssociationService, shared with the Hive
        // Contractors app's ExpenseAssociationController.
        app(ExpenseAssociationService::class)->removeFromCheck($this->expense, $check);

        $this->expense->refresh();
        $this->expense->load(['checks.bank_account.bank']);
        $this->dispatch('refreshComponent');
    }

    /**
     * The card is the company's own after all: every expense it paid goes
     * back to looking for a bank charge (App\Services\Expenses\
     * OutsideCardService).
     */
    public function unmarkOutsideCard(string $lastFour): void
    {
        $this->authorize('update', $this->expense);

        app(OutsideCardService::class)->unmark((int) $this->expense->belongs_to_vendor_id, $lastFour);

        $this->redirect(route('expenses.show', $this->expense), navigate: true);
    }
    
    // Manual toggle method for checkbox selection
    public function toggleSplit($id)
    {
        // If already selected, deselect it. Otherwise select the new one
        $this->selectedSplitId = ($this->selectedSplitId == $id) ? null : $id;
    }

    public function selectReceiptItem(int $receiptId, int $itemIndex): void
    {
        // Extracted to ReceiptLineItemDetail, shared with the Hive
        // Contractors app's ExpenseReceiptItemController.
        $item = app(ReceiptLineItemDetail::class)->forItem($this->expense, $receiptId, $itemIndex);

        if (! $item) {
            return;
        }

        $this->selectedItem = $item;
        $this->showItemModal = true;
    }

    /** The owning client/vendor shown in the details rail (blade used to query these per render). */
    #[Computed]
    public function belongsToClient()
    {
        return $this->expense->belongs_to_client_id
            ? \App\Models\Client::find($this->expense->belongs_to_client_id)
            : null;
    }

    #[Computed]
    public function belongsToVendor()
    {
        return $this->expense->belongs_to_vendor_id
            ? \App\Models\Vendor::find($this->expense->belongs_to_vendor_id)
            : null;
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
        // Only consider mismatch when there is exactly one PRIMARY receipt
        // with parsed line items. Notes-only supplement scans don't count:
        // an e-receipt plus its scan is still one purchase, and counting the
        // scan used to switch this banner off for exactly those expenses.
        $primaries = $this->expense->receipts->reject(fn (ExpenseReceipts $receipt) => $receipt->isSupplement());

        if ($primaries->count() !== 1) {
            return false;
        }

        $receipt = $primaries->first();
        if (!$receipt || !$receipt->receipt_items || !is_array($receipt->receipt_items)) {
            return false;
        }

        $receiptTotal  = (float) ($receipt->receipt_items['total'] ?? 0);
        $expenseAmount = (float) $this->expense->amount;

        // When a deposit/balance_due structure is present and the expense amount
        // matches the balance due, the expense correctly represents the final
        // payment — not a mismatch.
        $balanceDue = $receipt->receipt_items['balance_due'] ?? null;
        if ($balanceDue !== null && abs((float) $balanceDue - $expenseAmount) < 0.02) {
            return false;
        }

        return abs($receiptTotal - $expenseAmount) >= 0.02;
    }

    #[Title('Expense')]
    public function render()
    {
        $this->authorize('view', $this->expense);

        return view('livewire.expenses.show', [
            'check_associated_expenses' => $this->checkAssociatedExpenses(),
        ]);
    }

    /**
     * Other expenses paid by the same check(s) as this expense — extracted
     * to ExpenseAssociationService, shared with the Hive Contractors app's
     * ExpenseAssociationController.
     */
    protected function checkAssociatedExpenses()
    {
        return app(ExpenseAssociationService::class)->checkAssociatedExpenses($this->expense);
    }
}