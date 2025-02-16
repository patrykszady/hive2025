<?php

namespace App\Livewire\Expenses;

use App\Models\Expense;
use App\Models\ExpenseSplits;
use Livewire\Component;

// use App\Livewire\Forms\ExpenseSplitForm;

class ExpenseSplitsCreate extends Component
{
    // public ExpenseSplitForm $form;
    public Expense $expense;

    //keep track of expense_splits.*.amount sum
    public $expense_splits = [];

    public $expense_line_items = [];

    public $splits_count = 0;

    public $splits_total = 0;

    public $expense_total = 0;

    public $projects;

    public $distributions;

    protected $listeners = ['refreshComponent' => '$refresh', 'addSplits', 'addSplit', 'removeSplit', 'resetSplits'];

    public function rules()
    {
        return [
            'expense_splits.*.amount' => 'required|numeric|regex:/^-?\d+(\.\d{1,2})?$/|not_in:0',
            'expense_splits.*.project_id' => 'required',
            'expense_splits.*.reimbursment' => 'nullable',
            'expense_splits.*.note' => 'nullable',
            'expense_splits.*.items.*.checkbox' => 'nullable',
        ];
    }

    public function updated($field, $value)
    {
        if (substr($field, 0, 14) == 'expense_splits' && substr($field, -8) == 'checkbox') {
            //item belongs to a split (other splits should have this item disabled)
            $matches = [];
            preg_match_all('/\d+/', $field, $matches);
            $index_split = $matches[0][0];

            if ($value == true) {
                $this->expense_line_items->items[$matches[0][1]]->split_index = $index_split;
            } else {
                $this->expense_line_items->items[$matches[0][1]]->split_index = null;
            }

            //need to account for tax
            $items = collect($this->expense_line_items->items);
            $tax_rate = round($this->expense_line_items->total_tax / $this->expense_line_items->subtotal, 3);
            $tax_rate = 1 + $tax_rate;
            $expense_total = $this->expense_line_items->total;
            // dd($items, $this->expense_line_items);
            $this->expense_splits->transform(function ($split, $key) use ($items, $tax_rate) {
                $items_total = $items->where('split_index', $key)->whereNotNull('split_index')->sum('TotalPrice');
                $total_with_tax = $items_total * $tax_rate;

                //if last item without amount? check total...
                //last one. Adjust a penny $0.01 if $expense->amount != getSplitsSumProperty
                if ($items->whereNull('split_index')->count() == 0) {
                    // dd($this->getSplitsSumProperty());
                    // $difference = $expense_total - ($this->getSplitsSumProperty() + $split['amount']);
                    $split['amount'] = round($total_with_tax, 2);
                    // $this->splits_total = collect($this->expense_splits)->where('amount', '!=', '')->sum('amount');
                    // $split['amount'] = $this->getSplitsSumProperty();
                    // dd($expense_total - $this->getSplitsSumProperty());
                    // dd($split['amount'] + $this->getSplitsSumProperty());
                    //$this->getSplitsSumProperty()
                    // $difference = $this->getSplitsSumProperty();
                } else {
                    $split['amount'] = round($total_with_tax, 2);
                }

                // dd($split);

                return $split;
            });
        }

        $this->validateOnly($field);
    }

    public function getSplitsSumProperty()
    {
        $this->splits_total = collect($this->expense_splits)->where('amount', '!=', '')->sum('amount');

        return round($this->expense_total - $this->splits_total, 2);
    }

    public function addSplits($expense)
    {
        // dd($expense_total, $expense['id']);
        $this->expense = Expense::findOrFail($expense['id']);
        $expense = $this->expense;

        $receipt = $expense->receipts()->latest()->first();

        //!is_null($receipt->receipt_items->items
        if (! is_null($receipt) && ! is_null($receipt->receipt_items->items)) {
            $this->expense_line_items = $receipt->receipt_items;

            $items = [];
            foreach ($this->expense_line_items->items as $item_index => $line_item) {
                $items[$item_index] = ['checkbox' => false];
            }
        } else {
            $items = null;
        }

        $this->expense_total = $expense['amount'];

        if (! $expense->splits->isEmpty()) {
            $this->expense_splits = $expense->splits;
        } elseif (is_array($this->expense_splits) && ! empty($this->expense_splits)) {
            $this->expense_splits = collect($this->expense_splits);
        } elseif (! is_array($this->expense_splits)) {
            if ($this->expense_splits->isEmpty()) {
                $this->expense_splits = collect();
            }
        } else {
            $this->expense_splits = collect();
        }

        //if splits isset / comign from Expense.Update form.. otherwire
        if ($this->expense_splits->isEmpty()) {
            $this->expense_splits->push(['amount' => null, 'project_id' => null, 'items' => $items, 'reimbursment' => 'None']);
            $this->expense_splits->push(['amount' => null, 'project_id' => null, 'items' => $items, 'reimbursment' => 'None']);
            $this->splits_count = 2;
        } else {
            foreach ($this->expense_splits as $split_index => $split) {
                if (isset($split->receipt_items)) {
                    $split->items = $split->receipt_items;
                    foreach ($split->items as $item_index => $item) {
                        if ($item['checkbox'] == true) {
                            $this->expense_line_items->items[$item_index]->split_index = $split_index;
                        }
                    }
                }
            }

            $this->splits_count = count($this->expense_splits) - 1;
        }

        foreach ($this->expense_splits as $index => $split) {
            if ($split['project_id'] == null && isset($split['distribution_id'])) {
                $this->expense_splits[$index]['project_id'] = 'D:'.$split['distribution_id'];
            }
        }

        $this->getSplitsSumProperty();
        $this->modal('expense_splits_form_modal')->show();
    }

    public function addSplit()
    {
        $receipt = $this->expense->receipts()->latest()->first();

        if (! is_null($receipt) && ! is_null($receipt->receipt_items->items)) {
            foreach ($this->expense_line_items->items as $item_index => $line_item) {
                $items[$item_index] = ['checkbox' => false];
            }
        } else {
            $items = null;
        }

        $this->expense_splits->push(['amount' => null, 'project_id' => null, 'items' => $items, 'reimbursment' => 'None']);
        $this->splits_count = $this->splits_count + 1;
    }

    public function removeSplit($index)
    {
        $split_checked_items = collect($this->expense_splits[$index]['items'])->where('checkbox', true)->keys();
        foreach ($split_checked_items as $item_index) {
            $this->expense_line_items->items[$item_index]->split_index = null;
        }

        if (isset($this->expense_splits[$index]['id'])) {
            $split_to_remove = ExpenseSplits::findOrFail($this->expense_splits[$index]['id']);
            $split_to_remove->delete();
        }

        $this->splits_count = $this->splits_count - 1;
        unset($this->expense_splits[$index]);
    }

    public function resetSplits()
    {
        $this->splits_count = 0;
        $this->splits_total = 0;
        $this->expense_total = 0;
        $this->expense_splits = [];
        $this->expense_line_items = [];
    }

    public function split_store()
    {
        $this->validate();

        if (round($this->expense_total - $this->splits_total, 2) != 0.0) {
            $this->addError('expense_splits_total_match', 'Expense Amount and Splits Amounts must match');
        } else {
            //send all SPLITS data back to ExpenseForm view
            //send back to ExpenseForm... all validated and tested here
            $this->dispatch('hasSplits', $this->expense_splits)->to(ExpenseCreate::class);
            $this->modal('expense_splits_form_modal')->close();
            // $this->resetSplits();
        }
    }

    public function render()
    {
        $view_text = [
            'button_text' => 'Save Splits',
            'form_submit' => 'split_store',
        ];

        return view('livewire.expenses.splits-form', [
            'view_text' => $view_text,
        ]);
    }
}
