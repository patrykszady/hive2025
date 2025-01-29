<?php

namespace App\Livewire\Forms;

use App\Models\LineItem;
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
    }

    public function store()
    {
        $this->authorize('create', LineItem::class);
        $this->validate();

        LineItem::create($this->all());
        $this->reset();
    }

    public function update()
    {
        $this->authorize('create', LineItem::class);
        $this->validate();

        $this->line_item->update($this->all());
        $this->reset();
    }
}
