<?php

namespace App\Livewire\Forms;

use App\Models\EstimateLineItem;
use App\Models\LineItem;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Rule;
use Livewire\Form;

class EstimateLineItemForm extends Form
{
    use AuthorizesRequests;

    public ?LineItem $line_item;

    public ?EstimateLineItem $estimate_line_item;

    #[Rule('required|min:3')]
    public $category = null;

    #[Rule('nullable|min:3', as: 'sub category')]
    public $sub_category = null;

    #[Rule('required')]
    public $unit_type = null;

    #[Rule('required|numeric|min:0.1')]
    public $quantity = 1;

    #[Rule('required|numeric', as: 'amount')]
    public $cost = null;

    #[Rule('required|min:3', as: 'description')]
    public $desc = null;

    #[Rule('nullable|min:3')]
    public $notes = null;

    #[Rule('required')]
    public $total = '';

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
    }

    public function store()
    {
        $this->authorize('create', LineItem::class);
        $this->validate();

        EstimateLineItem::create([
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

        $this->reset();
    }
}
