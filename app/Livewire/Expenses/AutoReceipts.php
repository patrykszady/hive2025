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
     *
     * @return array<int, array<int, int>>
     */
    #[Computed]
    public function batches(): array
    {
        $rows = $this->baseQuery()->get([
            'id',
            'created_at',
            'auto_receipt_message_id',
            'auto_receipt_attachment_index',
            'auto_receipt_email_received_at',
        ]);

        if ($rows->isEmpty()) {
            return [];
        }

        $scoredBatches = [];

        $messageGroups = $rows->groupBy('auto_receipt_message_id');

        foreach ($messageGroups as $groupRows) {
            $ordered = $groupRows
                ->sort(function ($a, $b) {
                    $aIdx = $a->auto_receipt_attachment_index;
                    $bIdx = $b->auto_receipt_attachment_index;

                    if ($aIdx === null && $bIdx === null) {
                        return $b->created_at <=> $a->created_at;
                    }

                    if ($aIdx === null) {
                        return 1;
                    }

                    if ($bIdx === null) {
                        return -1;
                    }

                    if ((int) $aIdx === (int) $bIdx) {
                        return $b->created_at <=> $a->created_at;
                    }

                    return (int) $aIdx <=> (int) $bIdx;
                })
                ->values();

            $batchTimestamp = $ordered->firstWhere('auto_receipt_email_received_at', '!=', null)?->auto_receipt_email_received_at
                ?? $ordered->max('created_at');

            $scoredBatches[] = [
                'ids' => $ordered->pluck('id')->all(),
                'ts' => $batchTimestamp,
            ];
        }

        usort($scoredBatches, function (array $a, array $b): int {
            return $b['ts'] <=> $a['ts'];
        });

        return array_values(array_map(fn (array $batch) => $batch['ids'], $scoredBatches));
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
            ->whereNotNull('auto_receipt_message_id')
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
