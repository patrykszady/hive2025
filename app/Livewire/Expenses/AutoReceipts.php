<?php

namespace App\Livewire\Expenses;

use App\Models\AutoReceiptEmailBatch;
use App\Models\ExpenseReceipts;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

class AutoReceipts extends Component
{
    use AuthorizesRequests;

    /**
     * 1-based position within the auto-receipts batch list (newest first).
     */
    #[Url(as: 'p', except: 1)]
    public int $position = 1;

    /**
     * Per-receipt active tab name bound to Flux tabs (e.g. "receipt-123" or "ocr-123").
     */
    public string $activeTab = 'receipt';

    /**
     * Which kind of subtab is selected ("receipt" | "ocr"). This is the value that
     * persists when the user switches between sibling ExpenseReceipts (and across
     * prev/next page navigations via the session).
     */
    #[\Livewire\Attributes\Session]
    public string $subtab = 'receipt';

    protected $listeners = ['refreshComponent' => '$refresh'];

    public function mount(): void
    {
        $this->position = max(1, (int) $this->position);
    }

    public function next(): void
    {
        if ($this->position < $this->total) {
            $this->position++;
        }
    }

    public function previous(): void
    {
        if ($this->position > 1) {
            $this->position--;
        }
    }

    #[On('embedded-expense-updated')]
    public function advanceAfterUpdate(): void
    {
        if ($this->position < $this->total) {
            $nextPos = $this->position + 1;
            $this->redirect(route('expenses.auto-receipts', ['p' => $nextPos]), navigate: true);
        }
    }

    public function goTo(int $position): void
    {
        $this->position = max(1, min($position, max(1, $this->total)));
    }

    public function selectExpenseReceiptTab(int $receiptId): void
    {
        $this->activeTab = $this->subtab . '-' . $receiptId;
    }

    /**
     * Keep `$subtab` in sync whenever the inner Flux tab group updates
     * `$activeTab` (e.g. user clicks "Receipt" or "Processed Receipt").
     */
    public function updatedActiveTab(string $value): void
    {
        if (str_starts_with($value, 'ocr-')) {
            $this->subtab = 'ocr';
        } elseif (str_starts_with($value, 'receipt-')) {
            $this->subtab = 'receipt';
        }
    }

    /**
     * Total count of auto-receipts visible to the current user.
     */
    #[Computed]
    public function total(): int
    {
        return count($this->flatIds);
    }

    /**
     * Flat list of receipt ids in display order (newest first).
     *
     * @return array<int, int>
     */
    #[Computed]
    public function flatIds(): array
    {
        $batches = $this->batches;
        return empty($batches) ? [] : array_merge(...$batches);
    }

    /**
     * 1-based batch number and position-within-batch for the current receipt.
     *
     * @return array{batch:int,totalBatches:int,positionInBatch:int,batchSize:int}|null
     */
    #[Computed]
    public function batchInfo(): ?array
    {
        $batches = $this->batches;
        if (empty($batches)) {
            return null;
        }

        $offset = 0;
        foreach ($batches as $i => $ids) {
            $size = count($ids);
            if ($this->position <= $offset + $size) {
                return [
                    'batch' => $i + 1,
                    'totalBatches' => count($batches),
                    'positionInBatch' => $this->position - $offset,
                    'batchSize' => $size,
                ];
            }
            $offset += $size;
        }

        return null;
    }

    /**
     * All batches as arrays of receipt ids, newest batch first.
     * A single receipt id may appear in multiple batches when the same
     * paper receipt is rescanned/re-emailed — each batch shows the
     * delivery in its own slot.
     *
     * @return array<int, array<int, int>>
     */
    #[Computed]
    public function batches(): array
    {
        $vendorId = auth()->user()?->vendor?->id;

        $batches = AutoReceiptEmailBatch::query()
            ->with(['items' => function ($q) {
                $q->orderBy('attachment_index')->orderBy('id');
            }, 'items.expenseReceipt:id,expense_id', 'items.expenseReceipt.expense:id,belongs_to_vendor_id'])
            ->orderByDesc('email_received_at')
            ->orderByDesc('id')
            ->get();

        $out = [];
        foreach ($batches as $batch) {
            $ids = $batch->items
                ->filter(function ($item) use ($vendorId) {
                    $receipt = $item->expenseReceipt;
                    if (! $receipt) {
                        return false;
                    }
                    if (! $vendorId) {
                        return true;
                    }
                    return $receipt->expense?->belongs_to_vendor_id === $vendorId;
                })
                ->sortBy('attachment_index')
                ->values()
                ->pluck('expense_receipt_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            if (! empty($ids)) {
                $out[] = $ids;
            }
        }

        return $out;
    }

    /**
     * Single receipt at the current position (or null when none).
     */
    #[Computed]
    public function currentReceipt(): ?ExpenseReceipts
    {
        $ids = $this->flatIds;
        if (empty($ids)) {
            return null;
        }

        $idx = max(0, min($this->position - 1, count($ids) - 1));
        $id = $ids[$idx] ?? null;
        if ($id === null) {
            return null;
        }

        $receipt = ExpenseReceipts::query()
            ->with([
                'expense.vendor',
                'expense.project',
                'expense.distribution',
                'expense.receipts',
            ])
            ->find($id);

        if ($receipt && $receipt->expense) {
            $this->authorize('view', $receipt->expense);

            // Sibling receipts render through x-expenses.receipt, which reads
            // $receipt->expense->vendor — hand them their parent so the
            // back-reference never lazy-loads.
            $receipt->expense->receipts->each->setRelation('expense', $receipt->expense);
        }

        return $receipt;
    }

    #[Title('Recent Auto Receipts')]
    public function render()
    {
        // Make sure the inner Flux tab group has a matching tab name. We bind
        // `wire:model.live="activeTab"` per-receipt, so we always rebuild the
        // name from the persisted subtab kind + the currently-rendered receipt.
        $current = $this->currentReceipt;
        if ($current) {
            $this->activeTab = $this->subtab . '-' . $current->id;
        }

        return view('livewire.expenses.auto-receipts')->layout('components.layouts.app', [
            'fullscreenClasses' => '!p-0 h-full overflow-hidden flex flex-col',
        ]);
    }
}
