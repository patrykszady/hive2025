<?php

namespace App\Livewire\Expenses;

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
     * Receipts created within this many minutes of each other are
     * treated as belonging to the same upload / email batch.
     */
    protected const BATCH_GAP_MINUTES = 5;

    /**
     * 1-based position within the auto-receipts batch list (newest first).
     */
    #[Url(as: 'p', except: 1)]
    public int $position = 1;

    /**
     * Which tab is active on the receipt card (receipt | ocr).
     */
    public string $activeTab = 'receipt';

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
     *
     * @return array<int, array<int, int>>
     */
    #[Computed]
    public function batches(): array
    {
        $rows = $this->baseQuery()->get(['id', 'created_at']);

        $batches = [];
        $current = [];
        $prevTs = null;

        foreach ($rows as $row) {
            $ts = $row->created_at;

            if ($prevTs !== null && abs($prevTs->diffInMinutes($ts)) > self::BATCH_GAP_MINUTES) {
                $batches[] = $current;
                $current = [];
            }

            $current[] = $row->id;
            $prevTs = $ts;
        }

        if (! empty($current)) {
            $batches[] = $current;
        }

        return $batches;
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
            ])
            ->find($id);

        if ($receipt && $receipt->expense) {
            $this->authorize('view', $receipt->expense);
        }

        return $receipt;
    }

    /**
     * Base query: receipts attached to expenses the current user owns,
     * newest first.
     *
     * @return \Illuminate\Database\Eloquent\Builder<ExpenseReceipts>
     */
    protected function baseQuery()
    {
        $vendorId = auth()->user()?->vendor?->id;

        return ExpenseReceipts::query()
            ->whereHas('expense', function ($q) use ($vendorId) {
                if ($vendorId) {
                    $q->where('belongs_to_vendor_id', $vendorId);
                }
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    #[Title('Recent Auto Receipts')]
    public function render()
    {
        return view('livewire.expenses.auto-receipts');
    }
}
