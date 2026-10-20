<?php

namespace App\Services\Estimates;

use App\Models\EstimateLineItemAllowance;
use App\Models\LineItemAllowance;
use App\Services\AllowanceAggregator;
use Illuminate\Support\Collection;

/**
 * "Previously used" allowance suggestions for a catalog line item — the
 * shared body of App\Livewire\LineItems\EstimateLineItemCreate::
 * previousAllowances(), so the Hive Contractors app's "Add Line Item"
 * screen can offer the exact same autocomplete. The curated catalog
 * (App\Models\LineItemAllowance) wins when this line item has any; only
 * when it has none does this fall back to aggregating actual past usage
 * (App\Models\EstimateLineItemAllowance, across every estimate that used
 * this same line item) via AllowanceAggregator's concept clustering —
 * a different, deeper fallback than EstimateLineItemWriter::
 * globalAllowancesFor()'s vendor-wide catalog fallback, which this
 * doesn't touch.
 */
class EstimateAllowanceSuggestions
{
    public function __construct(private AllowanceAggregator $aggregator)
    {
    }

    /**
     * @return Collection<int, array{description: string, pricing_mode: string, unit_amount: ?float, amount: ?float}>
     */
    public function forLineItem(int $lineItemId): Collection
    {
        $globalAllowances = LineItemAllowance::query()
            ->where('line_item_id', $lineItemId)
            ->orderBy('id')
            ->get();

        if ($globalAllowances->isNotEmpty()) {
            return $globalAllowances
                ->map(fn (LineItemAllowance $allowance) => [
                    'description' => $allowance->description,
                    'pricing_mode' => $allowance->pricing_mode ?? ($allowance->unit_amount !== null ? 'per_unit' : 'lump_sum'),
                    'unit_amount' => $allowance->unit_amount !== null ? (float) $allowance->unit_amount : null,
                    'amount' => $allowance->amount !== null ? (float) $allowance->amount : null,
                ])
                ->filter(fn (array $allowance) => $allowance['description'] !== '')
                ->values();
        }

        $allowances = EstimateLineItemAllowance::query()
            ->whereHas('estimateLineItem', fn ($query) => $query->where('line_item_id', $lineItemId))
            ->with('estimateLineItem:id,line_item_id,name,unit_type,quantity')
            ->orderByDesc('id')
            ->get();

        return $this->aggregator->aggregate($allowances)
            ->map(fn (array $allowance) => [
                'description' => $allowance['description'],
                'pricing_mode' => $allowance['unit_amount'] !== null ? 'per_unit' : 'lump_sum',
                'unit_amount' => $allowance['unit_amount'],
                'amount' => null,
            ])
            ->filter(fn (array $allowance) => $allowance['description'] !== '')
            ->values();
    }
}
