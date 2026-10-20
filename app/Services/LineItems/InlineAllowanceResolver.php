<?php

namespace App\Services\LineItems;

use App\Models\EstimateLineItemAllowance;
use App\Models\LineItem;
use App\Services\AllowanceAggregator;
use Illuminate\Support\Collection;

/**
 * The inline allowance rows shown beneath each line item on the catalog —
 * the shared body of App\Livewire\LineItems\LineItemsIndex::inlineAllowances()
 * and the Hive Contractors app's LineItemController, so both surfaces show
 * the exact same rows: the curated global allowances when a line item has
 * any, and otherwise a historical fallback aggregated from that line item's
 * past use across every estimate (via AllowanceAggregator).
 */
class InlineAllowanceResolver
{
    public function __construct(private AllowanceAggregator $aggregator) {}

    /**
     * @param  Collection<int, LineItem>  $lineItems  each with its `allowances` relation already loaded (ordered by id)
     * @return Collection<int, Collection<int, array{description: string, pricing_mode: string, unit_amount: ?float, amount: ?float, unit_type: ?string}>> keyed by line item id
     */
    public function resolve(Collection $lineItems): Collection
    {
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

        $fallbackByLineItemId = $this->aggregator
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
}
