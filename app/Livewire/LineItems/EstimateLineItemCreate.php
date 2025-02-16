<?php

namespace App\Livewire\LineItems;

use App\Livewire\Forms\EstimateLineItemForm;
use App\Livewire\Projects\ProjectFinances;
use App\Models\Estimate;
use App\Models\LineItem;
use Livewire\Attributes\Computed;
use Livewire\Component;

class EstimateLineItemCreate extends Component
{
    public Estimate $estimate;

    public EstimateLineItemForm $form;

    public $section_id = null;

    public $line_item_id = null;

    public $edit_line_item = false;

    public $estimate_line_item = [];

    public $section_item_count = null;

    public $view_text = [
        'card_title' => 'Add Line Item',
        'button_text' => 'Add Item',
        'form_submit' => 'save',
    ];

    protected $listeners = ['addToEstimate', 'editOnEstimate'];

    public function rules()
    {
        return [
            'line_item_id' => 'nullable',
        ];
    }

    public function updated($field, $value)
    {
        if ($field === 'line_item_id') {
            $this->selected_line_item($value);
        }

        $this->validateOnly($field);
        if (in_array($field, ['form.quantity', 'form.cost'])) {
            $this->form->total = $this->getTotalLineItemProperty();
        }
    }

    #[Computed]
    public function line_items()
    {
        return LineItem::orderBy('created_at', 'DESC')->get()->keyBy('id');
    }

    public function selected_line_item($line_item_id)
    {
        $this->line_item_id = $line_item_id;
        $this->form->setLineItem($this->line_items[$line_item_id]);
        $this->form->total = $this->getTotalLineItemProperty();
    }

    public function getTotalLineItemProperty()
    {
        // $total = 0;
        // $total +=
        // dd(isset($this->form->quantity));
        if ($this->form->quantity == 0) {
            $quantity = 0;
        } else {
            $quantity = $this->form->quantity;
        }

        if ($this->form->cost == 0) {
            $cost = 0;
        } else {
            $cost = $this->form->cost;
        }

        $total = $quantity * $cost;
        $total = number_format((float) $total, 2, '.', '');

        return $total;
    }

    public function removeFromEstimate()
    {
        $this->estimate_line_item->delete();
        $this->modal('estimate_line_item_form_modal')->close();
        $this->dispatch('refreshComponent')->to('estimates.estimate-show');
        $this->dispatch('refresh')->to(ProjectFinances::class);
    }

    public function editOnEstimate($estimate_line_item_id)
    {
        $this->form->reset();
        $this->estimate_line_item = $this->estimate->estimate_line_items()->findOrFail($estimate_line_item_id);

        $this->form->setEstimateLineItem($this->estimate_line_item);
        $this->form->total = $this->getTotalLineItemProperty();

        $this->line_item_id = $this->estimate_line_item->line_item_id;

        $this->view_text = [
            'card_title' => 'Edit Line Item',
            'button_text' => 'Edit Item',
            'form_submit' => 'edit',
        ];

        $this->section_id = $this->estimate_line_item->section->id;
        $this->edit_line_item = true;
        $this->modal('estimate_line_item_form_modal')->show();

        $this->dispatch('refresh')->to(ProjectFinances::class);
    }

    public function addToEstimate($section_id)
    {
        $section = $this->estimate->estimate_sections()->findOrFail($section_id);
        $this->section_item_count = $section->estimate_line_items->count();
        $this->edit_line_item = false;
        $this->estimate_line_item = null;
        $this->line_item_id = null;
        $this->form->reset();

        $this->view_text = [
            'card_title' => 'Add Line Item',
            'button_text' => 'Add Item',
            'form_submit' => 'save',
        ];

        $this->section_id = $section->id;

        $this->modal('estimate_line_item_form_modal')->show();
    }

    public function edit()
    {
        $this->form->update();

        $this->modal('estimate_line_item_form_modal')->close();
        $this->dispatch('refreshComponent')->to('estimates.estimate-show');
        $this->dispatch('refresh')->to(ProjectFinances::class);
    }

    public function save()
    {
        $this->form->store();

        $this->modal('estimate_line_item_form_modal')->close();
        $this->section_item_count = null;
        $this->dispatch('refreshComponent')->to('estimates.estimate-show');
        $this->dispatch('refresh')->to(ProjectFinances::class);
    }

    public function render()
    {
        return view('livewire.line-items.estimate-line-item-create');
    }
}
