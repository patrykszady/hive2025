<?php

namespace App\Services;

use App\Models\EstimateLineItem;
use App\Models\EstimateLineItemAllowance;
use App\Models\LineItemAllowance;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class AllowanceReconciler
{
    /**
     * Match an estimate allowance against the global catalog for its line item,
     * collapsing legacy free-text rows (e.g. "Tile: $5/sqft") onto the canonical
     * global allowance ("Tile"). Returns the reconciled canonical values, or
     * null when no global allowance matches.
     *
     * @param  Collection<int, LineItemAllowance>  $globals
     * @return array{line_item_allowance_id: int, description: string, pricing_mode: string, unit_amount: ?string, amount: string}|null
     */
    public function reconcile(EstimateLineItemAllowance $allowance, EstimateLineItem $lineItem, Collection $globals): ?array
    {
        $global = $this->matchGlobal($allowance->description ?? '', $allowance->line_item_allowance_id, $globals);

        if (! $global) {
            return null;
        }

        $mode = ($global->pricing_mode ?? 'per_unit') === 'lump_sum' ? 'lump_sum' : 'per_unit';

        if ($mode === 'lump_sum') {
            return [
                'line_item_allowance_id' => $global->id,
                'description' => $global->description,
                'pricing_mode' => 'lump_sum',
                'unit_amount' => null,
                'amount' => number_format((float) $global->amount, 2, '.', ''),
            ];
        }

        $unitAmount = $global->unit_amount !== null ? (float) $global->unit_amount : null;
        $quantity = $lineItem->unit_type === 'no_unit' ? 1.0 : (float) ($lineItem->quantity ?: 1);
        $amount = $unitAmount !== null ? $unitAmount * $quantity : (float) $allowance->amount;

        return [
            'line_item_allowance_id' => $global->id,
            'description' => $global->description,
            'pricing_mode' => 'per_unit',
            'unit_amount' => $unitAmount !== null ? number_format($unitAmount, 2, '.', '') : null,
            'amount' => number_format($amount, 2, '.', ''),
        ];
    }

    /**
     * Find the global allowance that an estimate allowance corresponds to,
     * preferring an existing link, then an exact (case-insensitive) description
     * match, then a normalized match that strips a trailing price annotation.
     *
     * @param  Collection<int, LineItemAllowance>  $globals
     */
    public function matchGlobal(string $description, ?int $linkId, Collection $globals): ?LineItemAllowance
    {
        if ($linkId) {
            $linked = $globals->firstWhere('id', $linkId);

            if ($linked) {
                return $linked;
            }
        }

        $exact = Str::lower(trim($description));

        if ($exact === '') {
            return null;
        }

        $byExact = $globals->first(fn (LineItemAllowance $global) => Str::lower(trim($global->description)) === $exact);

        if ($byExact) {
            return $byExact;
        }

        $normalized = $this->normalizeKey($description);

        if ($normalized === '' || $normalized === $exact) {
            return null;
        }

        return $globals->first(fn (LineItemAllowance $global) => Str::lower(trim($global->description)) === $normalized);
    }

    /**
     * Normalize a description for matching by dropping a trailing price
     * annotation (everything from the first colon onward).
     */
    public function normalizeKey(string $description): string
    {
        return Str::lower(trim(Str::before($description, ':')));
    }
}
