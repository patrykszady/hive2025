<?php

namespace App\Livewire\LineItems;

use App\Models\EstimateLineItemAllowance;
use App\Models\LineItem;
use App\Services\AllowanceAggregator;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

class LineItemsIndex extends Component
{
    use AuthorizesRequests, WithPagination;

    public string $search = '';

    protected $listeners = ['refreshComponent' => '$refresh'];

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function line_items()
    {
        return LineItem::query()
            ->with(['allowances' => fn ($query) => $query->orderBy('id')])
            ->when($this->search !== '', function ($query) {
                $term = '%'.$this->search.'%';

                $query->where(function ($inner) use ($term) {
                    $inner->where('name', 'like', $term)
                        ->orWhere('desc', 'like', $term)
                        ->orWhere('notes', 'like', $term);
                });
            })
            ->latest()
            ->paginate(15);
    }

    /**
     * Inline allowances to render beneath each visible line item.
     * Prefer the curated global catalog, and fall back to historical
     * estimate-derived canonical allowances when globals are empty.
     *
     * @return Collection<int, Collection<int, array{description: string, pricing_mode: string, unit_amount: ?float, amount: ?float, unit_type: ?string}>>
     */
    #[Computed]
    public function inlineAllowances(): Collection
    {
        $lineItems = $this->line_items->getCollection();

        if ($lineItems->isEmpty()) {
            return collect();
        }

        $globalByLineItemId = $lineItems->mapWithKeys(function (LineItem $lineItem) {
            return [
                $lineItem->id => $lineItem->allowances
                    ->map(fn ($allowance) => [
                        'description' => $allowance->description,
                        'pricing_mode' => ($allowance->pricing_mode ?? 'per_unit') === 'lump_sum' ? 'lump_sum' : 'per_unit',
                        'unit_amount' => $allowance->unit_amount !== null ? (float) $allowance->unit_amount : null,
                        'amount' => $allowance->amount !== null ? (float) $allowance->amount : null,
                        'unit_type' => $lineItem->unit_type,
                    ])
                    ->values(),
            ];
        });

        $missingIds = $lineItems
            ->filter(fn (LineItem $lineItem) => $lineItem->allowances->isEmpty())
            ->pluck('id')
            ->values();

        if ($missingIds->isEmpty()) {
            return $globalByLineItemId;
        }

        $historicalAllowances = EstimateLineItemAllowance::query()
            ->with(['estimateLineItem:id,line_item_id,name,unit_type,quantity', 'estimateLineItem.line_item:id,name'])
            ->whereHas('estimateLineItem', fn ($query) => $query->whereIn('line_item_id', $missingIds))
            ->orderByDesc('id')
            ->get();

        $fallbackByLineItemId = app(AllowanceAggregator::class)
            ->aggregate($historicalAllowances)
            ->groupBy('line_item_id')
            ->map(fn (Collection $rows) => $rows->map(fn (array $row) => [
                'description' => $row['description'],
                'pricing_mode' => $row['unit_amount'] !== null ? 'per_unit' : 'lump_sum',
                'unit_amount' => $row['unit_amount'] !== null ? (float) $row['unit_amount'] : null,
                'amount' => null,
                'unit_type' => $row['unit_type'] ?? null,
            ])->values());

        foreach ($missingIds as $lineItemId) {
            $globalByLineItemId[$lineItemId] = $fallbackByLineItemId->get($lineItemId, collect());
        }

        return $globalByLineItemId;
    }

    #[Title('Line Items')]
    public function render()
    {
        $this->authorize('viewAny', LineItem::class);

        return view('livewire.line-items.index');
    }
}
