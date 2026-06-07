<?php

namespace App\Livewire\Forms;

use App\Models\EstimateLineItem;
use App\Models\EstimateLineItemAllowance;
use App\Models\LineItem;
use App\Models\LineItemAllowance;
use App\Services\AllowanceReconciler;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Attributes\Rule;
use Livewire\Form;

class EstimateLineItemForm extends Form
{
    use AuthorizesRequests;

    public ?LineItem $line_item;

    public ?EstimateLineItem $estimate_line_item;

    #[Rule('required|min:3')]
    public $category = '';

    #[Rule('nullable|min:3', as: 'sub category')]
    public $sub_category = '';

    #[Rule('required')]
    public $unit_type = '';

    #[Rule('required|numeric|min:0.1')]
    public $quantity = 1;

    #[Rule('required|numeric', as: 'amount')]
    public $cost = '';

    #[Rule('required|min:3', as: 'description')]
    public $desc = '';

    #[Rule('nullable|min:3')]
    public $notes = '';

    #[Rule('required')]
    public $total = '';

    /** @var array<int, array{id: ?int, description: string, pricing_mode: string, unit_amount: string, amount: string}> */
    public array $allowances = [];

    public function setLineItem(LineItem $line_item)
    {
        $this->line_item = $line_item;

        $this->desc = $line_item->desc;
        $this->notes = $line_item->notes;
        $this->category = $line_item->category;
        $this->sub_category = $line_item->sub_category;
        $this->unit_type = $line_item->unit_type;
        $this->cost = $line_item->cost;
        // $this->quantity = $line_item->quantity;
    }

    public function setEstimateLineItem(EstimateLineItem $estimate_line_item)
    {
        $this->estimate_line_item = $estimate_line_item;

        $this->desc = $estimate_line_item->desc;
        $this->notes = $estimate_line_item->notes;
        $this->category = $estimate_line_item->category;
        $this->sub_category = $estimate_line_item->sub_category;
        $this->unit_type = $estimate_line_item->unit_type;
        $this->cost = $estimate_line_item->cost;
        $this->quantity = $estimate_line_item->quantity;
        $this->total = $estimate_line_item->total;

        $globals = $this->globalAllowancesFor($estimate_line_item);

        $reconciler = app(AllowanceReconciler::class);

        $this->allowances = $estimate_line_item->allowances
            ->map(function (EstimateLineItemAllowance $a) use ($estimate_line_item, $globals, $reconciler) {
                $reconciled = $reconciler->reconcile($a, $estimate_line_item, $globals);

                if ($reconciled) {
                    return [
                        'id' => $a->id,
                        'description' => $reconciled['description'],
                        'pricing_mode' => $reconciled['pricing_mode'],
                        'unit_amount' => $reconciled['unit_amount'],
                        'amount' => $reconciled['amount'],
                    ];
                }

                return [
                    'id' => $a->id,
                    'description' => $a->description,
                    'pricing_mode' => $a->pricing_mode ?? ($a->unit_amount !== null ? 'per_unit' : 'lump_sum'),
                    'unit_amount' => $a->unit_amount,
                    'amount' => $a->amount,
                ];
            })
            ->values()
            ->toArray();
    }

    public function store()
    {
        $this->authorize('create', LineItem::class);
        $this->validate();

        $lineItem = EstimateLineItem::create([
            'estimate_id' => $this->component->estimate->id,
            'line_item_id' => $this->line_item->id,
            'section_id' => $this->component->section_id,
            'name' => $this->line_item->name,
            'category' => $this->category,
            'sub_category' => $this->sub_category,
            'unit_type' => $this->unit_type,
            'quantity' => $this->quantity,
            'cost' => $this->cost,
            'total' => $this->total,
            'desc' => $this->desc,
            'notes' => $this->notes,
            'order' => $this->component->section_item_count + 1,
        ]);

        $this->syncAllowances($lineItem);

        $this->reset();
    }

    public function update()
    {
        $this->authorize('create', LineItem::class);
        $this->validate();

        $this->estimate_line_item->update([
            'estimate_id' => $this->component->estimate->id,
            'line_item_id' => $this->estimate_line_item->line_item_id,
            'section_id' => $this->component->section_id,
            'name' => $this->estimate_line_item->name,
            'category' => $this->category,
            'sub_category' => $this->sub_category,
            'unit_type' => $this->unit_type,
            'quantity' => $this->quantity,
            'cost' => $this->cost,
            'total' => $this->total,
            'desc' => $this->desc,
            'notes' => $this->notes,
        ]);

        $this->syncAllowances($this->estimate_line_item);

        $this->reset();
    }

    /**
     * Sync allowance rows for the given estimate line item.
     */
    protected function syncAllowances(EstimateLineItem $lineItem): void
    {
        $keepIds = [];

        foreach ($this->allowances as $entry) {
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
                $allowance = EstimateLineItemAllowance::find($entry['id']);
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
     * Get the canonical allowance catalog for an estimate line item, falling
     * back to the vendor-wide catalog when the selected line item has no own
     * curated allowances yet.
     */
    protected function globalAllowancesFor(EstimateLineItem $lineItem): Collection
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
