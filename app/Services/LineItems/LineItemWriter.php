<?php

namespace App\Services\LineItems;

use App\Models\LineItem;

/**
 * Writes a catalog line item and its global allowance rows — the shared body
 * of App\Livewire\Forms\LineItemForm::store()/update() and the Hive
 * Contractors app's LineItemController, so both surfaces save the catalog
 * identically. LineItemObserver::creating() stamps belongs_to_vendor_id from
 * the authenticated vendor; nothing here needs to set it on create.
 */
class LineItemWriter
{
    /**
     * @param  array{name: string, desc: string, notes: ?string, category: string, sub_category: ?string, unit_type: string, cost: float|string}  $attributes
     * @param  array<int, array{id: ?int, description: string, pricing_mode: string, unit_amount: string, amount: string}>  $allowances
     */
    public function create(array $attributes, array $allowances): LineItem
    {
        $lineItem = LineItem::create($attributes);

        $this->syncAllowances($lineItem, $allowances);

        return $lineItem;
    }

    /**
     * @param  array{name: string, desc: string, notes: ?string, category: string, sub_category: ?string, unit_type: string, cost: float|string}  $attributes
     * @param  array<int, array{id: ?int, description: string, pricing_mode: string, unit_amount: string, amount: string}>  $allowances
     */
    public function update(LineItem $lineItem, array $attributes, array $allowances): LineItem
    {
        $lineItem->update($attributes);

        $this->syncAllowances($lineItem, $allowances);

        return $lineItem;
    }

    /**
     * Sync the editable allowance rows into the line item's global allowance
     * catalog, removing any rows that were deleted in the modal.
     *
     * @param  array<int, array{id: ?int, description: string, pricing_mode: string, unit_amount: string, amount: string}>  $allowances
     */
    public function syncAllowances(LineItem $lineItem, array $allowances): void
    {
        $keepIds = [];

        foreach ($allowances as $entry) {
            $description = trim($entry['description'] ?? '');

            if ($description === '') {
                continue;
            }

            $pricingMode = ($entry['pricing_mode'] ?? 'per_unit') === 'lump_sum' ? 'lump_sum' : 'per_unit';
            $unitAmount = $pricingMode === 'lump_sum' || ($entry['unit_amount'] ?? '') === ''
                ? null
                : (float) $entry['unit_amount'];
            $amount = $pricingMode === 'lump_sum' && ($entry['amount'] ?? '') !== ''
                ? (float) $entry['amount']
                : null;

            $attributes = [
                'description' => $description,
                'pricing_mode' => $pricingMode,
                'unit_amount' => $unitAmount,
                'amount' => $amount,
                'belongs_to_vendor_id' => $lineItem->belongs_to_vendor_id,
            ];

            // $entry['id'] is a client-controlled array value: resolve it
            // through this line item's own allowances so it can't be swapped
            // for another line item's (or tenant's) allowance id.
            $allowance = ! empty($entry['id']) ? $lineItem->allowances()->find($entry['id']) : null;

            if ($allowance) {
                $allowance->update($attributes);
            } else {
                $allowance = $lineItem->allowances()->create($attributes);
            }

            $keepIds[] = $allowance->id;
        }

        $lineItem->allowances()->whereNotIn('id', $keepIds)->delete();
    }
}
