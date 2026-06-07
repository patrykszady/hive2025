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

        if (preg_match('/^form\.allowances\.(\d+)\.pricing_mode$/', $field, $matches)) {
            $this->applyAllowancePricingMode((int) $matches[1]);
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

    public function addAllowance(): void
    {
        $mode = $this->form->unit_type && $this->form->unit_type !== 'no_unit' ? 'per_unit' : 'lump_sum';

        $this->form->allowances[] = ['id' => null, 'description' => '', 'pricing_mode' => $mode, 'unit_amount' => '', 'amount' => ''];

        $this->dispatch('allowance-added');
    }

    public function removeAllowance(int $index): void
    {
        unset($this->form->allowances[$index]);
        $this->form->allowances = array_values($this->form->allowances);
    }

    /**
     * Toggle an allowance between per-unit and lump-sum pricing, clearing the
     * value that no longer applies.
     */
    public function toggleAllowancePerUnit(int $index): void
    {
        $current = $this->form->allowances[$index]['pricing_mode'] ?? 'per_unit';

        $this->form->allowances[$index]['pricing_mode'] = $current === 'lump_sum' ? 'per_unit' : 'lump_sum';

        $this->applyAllowancePricingMode($index);
    }

    /**
     * Clear the amount that does not apply to the row's pricing mode.
     */
    protected function applyAllowancePricingMode(int $index): void
    {
        $mode = ($this->form->allowances[$index]['pricing_mode'] ?? 'per_unit') === 'lump_sum' ? 'lump_sum' : 'per_unit';

        if ($mode === 'lump_sum') {
            $this->form->allowances[$index]['unit_amount'] = '';
        } else {
            $this->form->allowances[$index]['amount'] = '';
        }
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
