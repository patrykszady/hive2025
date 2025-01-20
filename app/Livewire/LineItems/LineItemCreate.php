<?php

namespace App\Livewire\LineItems;

use App\Livewire\Forms\LineItemForm;
use App\Models\Estimate;
use App\Models\LineItem;
use Livewire\Attributes\Computed;
use Livewire\Component;

class LineItemCreate extends Component
{
    public ?Estimate $estimate;

    public LineItemForm $form;

    public $existing_line_item_id = null;

    public $view_text = [
        'card_title' => 'Add Line Item',
        'button_text' => 'Add Item',
        'form_submit' => 'save',
    ];

    protected $listeners = ['addItem', 'editItem'];

    protected function rules()
    {
        return [
            'existing_line_item_id' => 'required',
        ];
    }

    public function updated($field)
    {
        if ($field === 'form.name') {
            $this->existing_line_item_id = null;
        }
    }

    public function resetModal()
    {
        $this->form->reset();
        $this->resetValidation();
        $this->existing_line_item_id = null;
    }

    #[Computed]
    public function line_items()
    {
        return LineItem::orderBy('created_at', 'DESC')
            ->where('name', 'like', '%'.$this->form->name.'%')
            ->orWhere('desc', 'like', '%'.$this->form->name.'%')
            ->orWhere('notes', 'like', '%'.$this->form->name.'%')
            ->get();
    }

    public function addItem()
    {
        $this->resetModal();
        $this->view_text = [
            'card_title' => 'Add Line Item',
            'button_text' => 'Add Item',
            'form_submit' => 'save',
        ];

        $this->modal('line_item_form_modal')->show();
    }

    public function editItem(LineItem $line_item)
    {
        $this->resetModal();
        $this->existing_line_item_id = 'NEW';
        $this->view_text = [
            'card_title' => 'Edit Line Item',
            'button_text' => 'Edit Item',
            'form_submit' => 'edit',
        ];

        $this->form->setLineItem($line_item);
        $this->modal('line_item_form_modal')->show();
    }

    public function save()
    {
        $this->form->store();
        $this->modal('line_item_form_modal')->close();

        $this->resetModal();
        $this->dispatch('refreshComponent')->to('line-items.line-items-index');
    }

    public function edit()
    {
        $this->form->update();
        $this->modal('line_item_form_modal')->close();

        $this->resetModal();
        $this->dispatch('refreshComponent')->to('line-items.line-items-index');
    }

    public function render()
    {
        return view('livewire.line-items.form');
    }
}
