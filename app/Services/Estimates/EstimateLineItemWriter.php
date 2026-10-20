<?php

namespace App\Services\Estimates;

use App\Models\EstimateLineItem;
use App\Models\EstimateSection;
use App\Models\LineItem;
use App\Models\LineItemAllowance;
use App\Services\AllowanceReconciler;
use Illuminate\Support\Collection;

/**
 * Writes an estimate line item and its allowance rows — the shared body of
 * App\Livewire\Forms\EstimateLineItemForm::store()/update() and the Hive
 * Contractors app's EstimateLineItemController, so both surfaces create and
 * price a line item identically.
 */
class EstimateLineItemWriter
{
    /**
     * @param  array{category: string, sub_category: ?string, unit_type: string, quantity: float|string, cost: float|string, total: float|string, desc: string, notes: ?string}  $attributes
     * @param  array<int, array{id: ?int, description: string, pricing_mode: string, unit_amount: string, amount: string}>  $allowances
     */
    public function create(int $estimateId, EstimateSection $section, LineItem $lineItem, array $attributes, array $allowances): EstimateLineItem
    {
        $created = EstimateLineItem::create([
            'estimate_id' => $estimateId,
            'line_item_id' => $lineItem->id,
            'section_id' => $section->id,
            'name' => $lineItem->name,
            'category' => $attributes['category'],
            'sub_category' => $attributes['sub_category'] ?? null,
            'unit_type' => $attributes['unit_type'],
            'quantity' => $attributes['quantity'],
            'cost' => $attributes['cost'],
            'total' => $attributes['total'],
            'desc' => $attributes['desc'],
            'notes' => $attributes['notes'] ?? null,
            'order' => $section->estimate_line_items()->count() + 1,
        ]);

        $this->syncAllowances($created, $allowances);

        return $created;
    }

    /**
     * @param  array{category: string, sub_category: ?string, unit_type: string, quantity: float|string, cost: float|string, total: float|string, desc: string, notes: ?string}  $attributes
     * @param  array<int, array{id: ?int, description: string, pricing_mode: string, unit_amount: string, amount: string}>  $allowances
     */
    public function update(EstimateLineItem $lineItem, EstimateSection $section, array $attributes, array $allowances): EstimateLineItem
    {
        $lineItem->update([
            'estimate_id' => $section->estimate_id,
            'line_item_id' => $lineItem->line_item_id,
            'section_id' => $section->id,
            'name' => $lineItem->name,
            'category' => $attributes['category'],
            'sub_category' => $attributes['sub_category'] ?? null,
            'unit_type' => $attributes['unit_type'],
            'quantity' => $attributes['quantity'],
            'cost' => $attributes['cost'],
            'total' => $attributes['total'],
            'desc' => $attributes['desc'],
            'notes' => $attributes['notes'] ?? null,
        ]);

        $this->syncAllowances($lineItem, $allowances);

        return $lineItem;
    }

    /**
     * Sync allowance rows for the given estimate line item.
     *
     * @param  array<int, array{id: ?int, description: string, pricing_mode: string, unit_amount: string, amount: string}>  $allowances
     */
    public function syncAllowances(EstimateLineItem $lineItem, array $allowances): void
    {
        $keepIds = [];

        foreach ($allowances as $entry) {
            $description = trim($entry['description'] ?? '');
            $amount = (float) ($entry['amount'] ?? 0);
            $pricingMode = ($entry['pricing_mode'] ?? 'per_unit') === 'lump_sum' ? 'lump_sum' : 'per_unit';
            $unitAmount = $pricingMode === 'lump_sum' || ($entry['unit_amount'] ?? '') === ''
                ? null
                : (float) $entry['unit_amount'];

            if ($description === '' && $amount <= 0) {
                continue;
            }

            $globalAllowance = $this->resolveGlobalAllowance($lineItem, $description, $pricingMode, $unitAmount, $amount);

            if (! empty($entry['id'])) {
                // $entry['id'] is a client-controlled array value: resolve it
                // through this line item's own allowances so it can't be
                // swapped for another line item's (or tenant's) allowance id.
                $allowance = $lineItem->allowances()->find($entry['id']);
                if ($allowance) {
                    $allowance->update([
                        'line_item_allowance_id' => $globalAllowance?->id,
                        'description' => $description,
                        'pricing_mode' => $pricingMode,
                        'unit_amount' => $unitAmount,
                        'amount' => $amount,
                    ]);
                    $keepIds[] = $allowance->id;

                    continue;
                }
            }

            $allowance = $lineItem->allowances()->create([
                'line_item_allowance_id' => $globalAllowance?->id,
                'description' => $description,
                'pricing_mode' => $pricingMode,
                'unit_amount' => $unitAmount,
                'amount' => $amount,
            ]);
            $keepIds[] = $allowance->id;
        }

        // Soft-delete removed allowances
        $lineItem->allowances()->whereNotIn('id', $keepIds)->delete();
    }

    /**
     * Find or create the global allowance for this line item's catalog entry,
     * mirroring how an estimate line item references a global line item.
     */
    protected function resolveGlobalAllowance(EstimateLineItem $lineItem, string $description, string $pricingMode, ?float $unitAmount, float $amount): ?LineItemAllowance
    {
        if ($description === '' || ! $lineItem->line_item_id) {
            return null;
        }

        $reconciler = app(AllowanceReconciler::class);
        $sameLineItemGlobals = LineItemAllowance::query()
            ->where('line_item_id', $lineItem->line_item_id)
            ->orderBy('id')
            ->get();

        $globalAllowance = $reconciler->matchGlobal($description, null, $sameLineItemGlobals);

        if (! $globalAllowance && $sameLineItemGlobals->isEmpty()) {
            $globalAllowance = $reconciler->matchGlobal($description, null, $this->vendorAllowancesFor($lineItem));
        }

        if (! $globalAllowance) {
            $globalAllowance = LineItemAllowance::firstOrNew([
                'line_item_id' => $lineItem->line_item_id,
                'description' => $description,
            ]);
        }

        if ((int) $globalAllowance->line_item_id === (int) $lineItem->line_item_id) {
            $globalAllowance->pricing_mode = $pricingMode;
            $globalAllowance->unit_amount = $unitAmount;
            $globalAllowance->amount = $amount;
            $globalAllowance->belongs_to_vendor_id = $lineItem->line_item?->belongs_to_vendor_id
                ?? $globalAllowance->belongs_to_vendor_id;
            $globalAllowance->save();
        }

        return $globalAllowance;
    }

    /**
     * Create an offsetting "Credit" copy of a line item locked into the
     * signed contract — the way to back it out of the contract without
     * touching the original. Returns null when the item isn't actually
     * locked (credits only exist for signed-contract items) — the shared
     * body of App\Livewire\LineItems\EstimateLineItemCreate::creditToChangeOrder()
     * and the Hive Contractors app's EstimateLineItemController::credit().
     */
    public function creditToChangeOrder(EstimateLineItem $original, float $quantity, float $cost): ?EstimateLineItem
    {
        if (! $original->section?->isLocked()) {
            return null;
        }

        $section = app(EstimateSectionWriter::class)->resolveChangeOrderSection($original->estimate);

        $quantity = $quantity ?: ($original->quantity ?: 1);
        $total = number_format($quantity * $cost, 2, '.', '');

        return EstimateLineItem::create([
            'estimate_id' => $original->estimate_id,
            'line_item_id' => $original->line_item_id,
            'credit_for_id' => $original->id,
            'section_id' => $section->id,
            'name' => 'Credit: '.$original->name,
            'category' => $original->category,
            'sub_category' => $original->sub_category,
            'unit_type' => $original->unit_type,
            'quantity' => $quantity,
            'cost' => -$cost,
            'total' => -$total,
            // The credit doesn't repeat the original scope text — it just
            // points back at the credited line item by its estimate number.
            'desc' => 'Credit for Line Item #'.$this->lineItemNumber($original).': '.$original->name,
            'notes' => null,
            'order' => $section->estimate_line_items()->count() + 1,
        ]);
    }

    /**
     * Reorders a line item to the given 0-indexed position, optionally
     * moving it into a different section first — the shared body of
     * App\Livewire\Estimates\EstimateShow::sort_line_item() and the Hive
     * Contractors app's EstimateLineItemController::move(). $section must
     * already be resolved as one of the estimate's own sections (same
     * check sort_line_item makes via findOrFail before calling this).
     */
    public function moveLineItem(EstimateLineItem $lineItem, int $position, ?EstimateSection $section = null): EstimateLineItem
    {
        $oldSectionId = $lineItem->section_id;

        if ($section && $section->id !== $oldSectionId) {
            // Cross-section move: close the gap in the old section.
            $lineItem->displace();

            $lineItem->section_id = $section->id;
            $lineItem->save();

            // Refresh the relationship so scopeSortable picks up the new section.
            $lineItem->unsetRelation('section');

            // Insert at the correct position in the new section.
            $lineItem->move($position);

            // Recalculate totals on both sections.
            $oldSection = EstimateSection::find($oldSectionId);
            $newSection = $section;

            foreach ([$oldSection, $newSection] as $affected) {
                if (! $affected) {
                    continue;
                }

                $affected->total = $affected->estimate_line_items()->sum('total');
                $affected->save();

                if ($affected->bid_id) {
                    $bid = \App\Models\Bid::find($affected->bid_id);
                    if ($bid) {
                        $bid->amount = EstimateSection::where('bid_id', $bid->id)->sum('total');
                        $bid->save();
                    }
                }
            }
        } else {
            // Same-section reorder.
            $lineItem->move($position);
        }

        return $lineItem->fresh();
    }

    /**
     * The "{section}.{item}" number a line item displays as on the estimate
     * (both ordered by their order column, matching the page's default sort).
     */
    public function lineItemNumber(EstimateLineItem $lineItem): string
    {
        $sectionIndex = $lineItem->estimate->estimate_sections()
            ->orderBy('order')
            ->pluck('id')
            ->search($lineItem->section_id);

        $itemIndex = $lineItem->section->estimate_line_items()
            ->orderBy('order')
            ->pluck('id')
            ->search($lineItem->id);

        return ($sectionIndex === false ? '?' : $sectionIndex + 1).'.'.($itemIndex === false ? '?' : $itemIndex + 1);
    }

    /**
     * Get the canonical allowance catalog for an estimate line item, falling
     * back to the vendor-wide catalog when the selected line item has no own
     * curated allowances yet.
     */
    public function globalAllowancesFor(EstimateLineItem $lineItem): Collection
    {
        $sameLineItemGlobals = $lineItem->line_item_id
            ? LineItemAllowance::query()
                ->where('line_item_id', $lineItem->line_item_id)
                ->orderBy('id')
                ->get()
            : collect();

        if ($sameLineItemGlobals->isNotEmpty()) {
            return $sameLineItemGlobals;
        }

        return $this->vendorAllowancesFor($lineItem);
    }

    /**
     * Get the vendor-wide allowance catalog for the estimate line item.
     */
    protected function vendorAllowancesFor(EstimateLineItem $lineItem): Collection
    {
        $vendorId = $lineItem->line_item?->belongs_to_vendor_id
            ?? LineItem::query()->whereKey($lineItem->line_item_id)->value('belongs_to_vendor_id')
            ?? $lineItem->estimate?->belongs_to_vendor_id;

        if (! $vendorId) {
            return collect();
        }

        return LineItemAllowance::query()
            ->where('belongs_to_vendor_id', $vendorId)
            ->orderBy('line_item_id')
            ->orderBy('id')
            ->get();
    }
}
