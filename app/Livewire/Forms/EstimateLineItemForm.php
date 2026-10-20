<?php

namespace App\Livewire\Forms;

use App\Models\EstimateLineItem;
use App\Models\EstimateLineItemAllowance;
use App\Models\LineItem;
use App\Services\AllowanceReconciler;
use App\Services\Estimates\EstimateLineItemWriter;
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

        // section_id is a plain client-writable property: resolve it
        // through this estimate's own sections so it can't be pointed at
        // another tenant's (or another estimate's) section.
        $section = $this->component->estimate->estimate_sections()->findOrFail($this->component->section_id);

        app(EstimateLineItemWriter::class)->create(
            $this->component->estimate->id,
            $section,
            $this->line_item,
            [
                'category' => $this->category,
                'sub_category' => $this->sub_category,
                'unit_type' => $this->unit_type,
                'quantity' => $this->quantity,
                'cost' => $this->cost,
                'total' => $this->total,
                'desc' => $this->desc,
                'notes' => $this->notes,
            ],
            $this->allowances,
        );

        $this->reset();
    }

    public function update()
    {
        $this->authorize('create', LineItem::class);
        $this->validate();

        // section_id is a plain client-writable property: resolve it
        // through this estimate's own sections so it can't be pointed at
        // another tenant's (or another estimate's) section.
        $section = $this->component->estimate->estimate_sections()->findOrFail($this->component->section_id);

        app(EstimateLineItemWriter::class)->update(
            $this->estimate_line_item,
            $section,
            [
                'category' => $this->category,
                'sub_category' => $this->sub_category,
                'unit_type' => $this->unit_type,
                'quantity' => $this->quantity,
                'cost' => $this->cost,
                'total' => $this->total,
                'desc' => $this->desc,
                'notes' => $this->notes,
            ],
            $this->allowances,
        );

        $this->reset();
    }

    /**
     * Get the canonical allowance catalog for an estimate line item, falling
     * back to the vendor-wide catalog when the selected line item has no own
     * curated allowances yet. Delegates to EstimateLineItemWriter, the single
     * source of this logic shared with the Hive Contractors app's API.
     *
     * @return Collection<int, \App\Models\LineItemAllowance>
     */
    protected function globalAllowancesFor(EstimateLineItem $lineItem): Collection
    {
        return app(EstimateLineItemWriter::class)->globalAllowancesFor($lineItem);
    }
}
