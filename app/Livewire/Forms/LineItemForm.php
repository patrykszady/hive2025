<?php

namespace App\Livewire\Forms;

use App\Models\EstimateLineItemAllowance;
use App\Models\LineItem;
use App\Models\LineItemAllowance;
use App\Services\AllowanceAggregator;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Rule;
use Livewire\Form;

class LineItemForm extends Form
{
    use AuthorizesRequests;

    public ?LineItem $line_item;

    #[Rule('required|min:3')]
    public $name = null;

    #[Rule('required|min:3', as: 'description')]
    public $desc = null;

    #[Rule('nullable|min:3')]
    public $notes = null;

    #[Rule('required|min:3')]
    public $category = null;

    #[Rule('nullable|min:3', as: 'sub category')]
    public $sub_category = null;

    #[Rule('required')]
    public $unit_type = null;

    #[Rule('required|numeric|regex:/^-?\d+(\.\d{1,2})?$/', as: 'amount')]
    public $cost = null;

    /** @var array<int, array{id: ?int, description: string, pricing_mode: string, unit_amount: string, amount: string}> */
    public array $allowances = [];

    // #[Rule('required|min:0.01')]
    // public $quantity = '';

    // #[Rule('required')]
    // public $total = '';

    //MESSAGES:
    //     'line_item.cost.regex' => 'Amount format is incorrect. Format is 2145.36. No commas and only two digits after decimal allowed. If amount is under $1.00, use 00.XX',
    //     'line_item.quantity.min_digits' => 'Quantity must be a full number with no decimals.',

    public function setLineItem(LineItem $line_item)
    {
        $this->line_item = $line_item;

        $this->name = $line_item->name;
        $this->desc = $line_item->desc;
        $this->notes = $line_item->notes;
        $this->category = $line_item->category;
        $this->sub_category = $line_item->sub_category;
        $this->unit_type = $line_item->unit_type;
        $this->cost = $line_item->cost;

        $this->loadAllowances($line_item);
    }

    /**
     * Populate the editable allowance catalog for the line item, preferring
     * curated global allowances and falling back to the canonical allowances
     * derived from past estimates so the catalog can be seeded.
     */
    protected function loadAllowances(LineItem $line_item): void
    {
        $globals = $line_item->allowances()->orderBy('id')->get();

        if ($globals->isNotEmpty()) {
            $this->allowances = $globals
                ->map(fn (LineItemAllowance $a) => [
                    'id' => $a->id,
                    'description' => $a->description,
                    'pricing_mode' => $a->pricing_mode ?? ($a->unit_amount !== null ? 'per_unit' : 'lump_sum'),
                    'unit_amount' => $a->unit_amount !== null ? number_format((float) $a->unit_amount, 2, '.', '') : '',
                    'amount' => $a->amount !== null ? number_format((float) $a->amount, 2, '.', '') : '',
                ])
                ->values()
                ->toArray();

            return;
        }

        $derived = app(AllowanceAggregator::class)->aggregate(
            EstimateLineItemAllowance::query()
                ->whereHas('estimateLineItem', fn ($query) => $query->where('line_item_id', $line_item->id))
                ->with('estimateLineItem:id,line_item_id,name,unit_type,quantity')
                ->orderByDesc('id')
                ->get()
        );

        $this->allowances = $derived
            ->map(fn (array $a) => [
                'id' => null,
                'description' => $a['description'],
                'pricing_mode' => $a['unit_amount'] !== null ? 'per_unit' : 'lump_sum',
                'unit_amount' => $a['unit_amount'] !== null ? number_format((float) $a['unit_amount'], 2, '.', '') : '',
                'amount' => '',
            ])
            ->values()
            ->toArray();
    }

    public function store()
    {
        $this->authorize('create', LineItem::class);
        $this->validate();

        $lineItem = LineItem::create($this->except('allowances', 'line_item'));

        $this->syncAllowances($lineItem);

        $this->reset();
    }

    public function update()
    {
        $this->authorize('create', LineItem::class);
        $this->validate();

        $this->line_item->update($this->except('allowances', 'line_item'));

        $this->syncAllowances($this->line_item);

        $this->reset();
    }

    /**
     * Sync the editable allowance rows into the line item's global allowance
     * catalog, removing any rows that were deleted in the modal.
     */
    protected function syncAllowances(LineItem $lineItem): void
    {
        $keepIds = [];

        foreach ($this->allowances as $entry) {
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

            $allowance = ! empty($entry['id']) ? LineItemAllowance::find($entry['id']) : null;

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
