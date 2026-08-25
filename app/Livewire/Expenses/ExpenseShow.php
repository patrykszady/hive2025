<?php

namespace App\Livewire\Expenses;

use App\Models\Check;
use App\Models\Expense;
use App\Models\ExpenseReceipts;
use App\Models\ReceiptLineItemDesc;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Title;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Spatie\Activitylog\Models\Activity;

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

        if ((int) $this->expense->check_id === (int) $check->id) {
            $this->expense->check_id = null;
            $this->expense->save();
        }

        if ($this->expense->checks()->where('checks.id', $check->id)->exists()) {
            $this->expense->checks()->detach($check->id);
            $this->expense->searchable();
        }

        // Reimbursement deductions must stay negative — use the shared recalc
        $check->recalculateAmount();

        $this->expense->refresh();
        $this->expense->load(['checks.bank_account.bank']);
        $this->dispatch('refreshComponent');
    }
    
    // Manual toggle method for checkbox selection
    public function toggleSplit($id)
    {
        // If already selected, deselect it. Otherwise select the new one
        $this->selectedSplitId = ($this->selectedSplitId == $id) ? null : $id;
    }

    public function selectReceiptItem(int $receiptId, int $itemIndex): void
    {
        // Always fetch the latest receipt row to avoid stale in-memory relation data.
        $receipt = ExpenseReceipts::query()
            ->where('expense_id', $this->expense->id)
            ->find($receiptId);

        if (! $receipt) {
            $receipt = $this->expense->orderedReceipts->firstWhere('id', $receiptId);
        }

        $items = $receipt?->receipt_items['items'] ?? [];
        $item = $items[$itemIndex] ?? null;

        if (! $item) {
            return;
        }

        // Enrich with receipt_line_item_descs
        $desc = ReceiptLineItemDesc::where('expense_receipt_id', $receiptId)
            ->where('item_index', $itemIndex)
            ->first();

        if ($desc) {
            if (empty($item['image_url']) && ! empty($desc->product_image_url)) {
                $item['image_url'] = $desc->product_image_url;
            }

            if (empty($item['product_url']) && ! empty($desc->product_url)) {
                $item['product_url'] = $desc->product_url;
            }

            if (empty($item['Area']) && ! empty($desc->area)) {
                $item['Area'] = $desc->area;
            }
        }

        $item['_vendor_name'] = $this->expense->vendor?->business_name;
        $item['_expense_date'] = $this->expense->date?->format('M j, Y');

        // Fetch activity log changes for this specific item
        if ($receipt?->is_material_order) {
            $item['_history'] = Activity::where('log_name', 'materials')
                ->where('subject_type', 'App\\Models\\ExpenseReceipts')
                ->where('subject_id', $receipt->id)
                ->latest()
                ->get()
                ->flatMap(function ($log) use ($itemIndex) {
                    $tz = auth()->user()?->vendor?->timezone ?? config('app.timezone');
                    return collect($log->properties['items'] ?? [])
                        ->filter(fn ($change) => ($change['item_index'] ?? null) === $itemIndex)
                        ->map(fn ($change) => array_merge($change, ['date' => $log->created_at->timezone($tz)->format('n/j/y g:iA')]));
                })
                ->values()
                ->toArray();
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
        // Only consider mismatch when there is exactly one receipt with parsed line items
        if ($this->expense->receipts->count() !== 1) {
            return false;
        }

        $receipt = $this->expense->receipts->first();
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
     * Other expenses paid by the same check(s) as this expense — via both the
     * legacy expenses.check_id FK and the check_expense pivot.
     */
    protected function checkAssociatedExpenses()
    {
        $checkIds = $this->expense->checks()->pluck('checks.id')
            ->when($this->expense->check_id, fn ($ids) => $ids->push($this->expense->check_id))
            ->unique()
            ->values();

        if ($checkIds->isEmpty()) {
            return collect();
        }

        return Expense::query()
            ->where('id', '!=', $this->expense->id)
            ->where(function ($query) use ($checkIds) {
                $query->whereIn('check_id', $checkIds)
                    ->orWhereHas('checks', fn ($q) => $q->whereIn('checks.id', $checkIds));
            })
            ->with('project')
            ->orderBy('date')
            ->get()
            ->unique('id')
            ->values();
    }
}