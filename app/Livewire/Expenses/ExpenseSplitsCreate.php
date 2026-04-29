<?php

namespace App\Livewire\Expenses;

use App\Models\Expense;
use App\Models\ExpenseSplits;
use Livewire\Component;
use Illuminate\Support\Collection;

// use App\Livewire\Forms\ExpenseSplitForm;

class ExpenseSplitsCreate extends Component
{
    // public ExpenseSplitForm $form;
    public ?Expense $expense = null;

    //keeps track of expense_splits.*.amount sum
    public $expense_splits = [];
    public $expense_line_items = [];
    public $splits_count = 0;
    public $splits_total = 0;
    public $expense_total = 0;

    //comes from ExpenseCreate
    public $projects = [];

    public $distributions;

    protected $listeners = ['refreshComponent' => '$refresh', 'addSplits', 'addSplit', 'removeSplit', 'resetSplits'];

    /**
     * Ensure $expense_splits is always a Collection when we need collection methods.
     */
    protected function ensureSplitsCollection(): void
    {
        if (!($this->expense_splits instanceof Collection)) {
            $this->expense_splits = collect($this->expense_splits ?? []);
        }
    }

    /**
     * Ensure $this->expense_line_items is an object with its items as objects so we can safely attach split_index.
     */
    protected function normalizeLineItemsStructure(): void
    {
        if (empty($this->expense_line_items)) {
            return;
        }

        // If it's an array, cast top-level to object.
        if (is_array($this->expense_line_items)) {
            $this->expense_line_items = (object) $this->expense_line_items;
        }

        // If items key exists and is array, convert each item to object for property access.
        if (isset($this->expense_line_items->items) && is_array($this->expense_line_items->items)) {
            foreach ($this->expense_line_items->items as $idx => $raw) {
                if (is_array($raw)) {
                    $raw = (object) $raw;
                }
                if (! isset($raw->split_index)) {
                    $raw->split_index = null;
                }
                $this->expense_line_items->items[$idx] = $raw;
            }
        }
    }

    /**
     * Safely get (and optionally initialize) a line item object at index.
     */
    protected function &lineItemRef(int $index)
    {
        // Initialize structure if needed
        $this->normalizeLineItemsStructure();
        if (! isset($this->expense_line_items->items[$index])) {
            $this->expense_line_items->items[$index] = (object) ['split_index' => null];
        } elseif (is_array($this->expense_line_items->items[$index])) {
            $this->expense_line_items->items[$index] = (object) $this->expense_line_items->items[$index];
            if (! isset($this->expense_line_items->items[$index]->split_index)) {
                $this->expense_line_items->items[$index]->split_index = null;
            }
        } elseif (! isset($this->expense_line_items->items[$index]->split_index)) {
            $this->expense_line_items->items[$index]->split_index = null;
        }
        return $this->expense_line_items->items[$index];
    }

    /**
     * Reindex splits to contiguous numeric keys and recalc item assignments, enforcing uniqueness.
     */
    protected function reindexSplitsAndRecalc(): void
    {
        $this->ensureSplitsCollection();
        $this->expense_splits = $this->expense_splits->values();

        // Reset all line item assignments
        if (is_object($this->expense_line_items) && isset($this->expense_line_items->items)) {
            foreach ($this->expense_line_items->items as $li) {
                if (is_object($li)) {
                    $li->split_index = null;
                }
            }

            // Enforce uniqueness: first split with checkbox=true claims the item; others are unchecked
            foreach ($this->expense_splits as $sidx => $split) {
                if (isset($split['items']) && is_array($split['items'])) {
                    $changed = false;
                    foreach ($split['items'] as $itemIndex => $item) {
                        $checked = is_array($item) ? ($item['checkbox'] ?? false) : (bool)$item;
                        if ($checked && isset($this->expense_line_items->items[$itemIndex])) {
                            if ($this->expense_line_items->items[$itemIndex]->split_index === null) {
                                $this->expense_line_items->items[$itemIndex]->split_index = $sidx;
                            } else {
                                // Already claimed by earlier split; uncheck in this split
                                $split['items'][$itemIndex]['checkbox'] = false;
                                $changed = true;
                            }
                        }
                    }
                    if ($changed) {
                        $this->expense_splits->put($sidx, $split);
                    }
                }
            }
        }

        $this->splits_count = $this->expense_splits->count();
    }

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
            $this->ensureSplitsCollection();
            $this->expense_splits = $this->expense_splits->values();
            //item belongs to a split (other splits should have this item disabled)
            $matches = [];
            preg_match_all('/\d+/', $field, $matches);
            $index_split = $matches[0][0];
            $item_index = $matches[0][1];

            if ($value == true) {
                // Assign to this split and uncheck the same item in other splits
                $li =& $this->lineItemRef((int)$item_index);
                $li->split_index = (int) $index_split;

                foreach ($this->expense_splits as $k => $_split) {
                    if ((int)$k !== (int)$index_split && isset($_split['items'][$item_index]['checkbox']) && $_split['items'][$item_index]['checkbox'] === true) {
                        $_split['items'][$item_index]['checkbox'] = false;
                        $this->expense_splits->put($k, $_split);
                    }
                }
            } else {
                $li =& $this->lineItemRef((int)$item_index);
                if ($li->split_index == $index_split) {
                    $li->split_index = null;
                }
            }


            $items = collect($this->expense_line_items->items);
            //need to account for tax
            $tax_rate = round($this->expense_line_items->total_tax / $this->expense_line_items->subtotal, 3);
            $tax_rate = 1 + $tax_rate;

            $this->ensureSplitsCollection();
            $this->expense_splits = $this->expense_splits->transform(function ($split, $key) use ($items, $tax_rate) {
                $items_total = $items->where('split_index', $key)->whereNotNull('split_index')->sum('TotalPrice');
                $total_with_tax = $items_total * $tax_rate;

                //if last item without amount? check total...
                //last one. Adjust a penny $0.01 if $expense->amount != getSplitsSumProperty
                if ($items->whereNull('split_index')->count() == 0) {
                    $split['amount'] = round($total_with_tax, 2);
                    //$this->getSplitsSumProperty()
                } else {
                    $split['amount'] = round($total_with_tax, 2);
                }

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

    public function addSplits($expense = null, $amount = null)
    {
        // Handle both integer ID and array with 'id' key
        $expenseId = is_array($expense) ? ($expense['id'] ?? null) : $expense;

        if ($expenseId) {
            $this->expense = Expense::findOrFail($expenseId);
        } else {
            // No persisted expense yet (e.g. creating an expense from a
            // transaction with no receipt). We can still build splits in memory
            // and the parent ExpenseCreate component will persist them on save.
            $this->expense = null;
        }

        $receipt = $this->expense?->receipts()->latest()->first();

        if (! is_null($receipt) && ! is_null($receipt->receipt_items['items'] ?? null)) {
            $this->expense_line_items = $receipt->receipt_items; // array or object
            $this->normalizeLineItemsStructure();

            // Default items structure for a new split (all unchecked)
            $defaultItems = [];
            foreach ($this->expense_line_items->items as $item_index => $line_item) {
                $defaultItems[$item_index] = ['checkbox' => false];
            }
        } else {
            $defaultItems = null;
        }

        $this->expense_total = $this->expense?->amount ?? (float) $amount;

        if ($this->expense && ! $this->expense->splits->isEmpty()) {
            // Normalize existing splits to plain arrays (no Eloquent models)
            $normalized = [];
            foreach ($expense->splits->values() as $sidx => $split) {
                // Normalize items
                if (is_array($split->receipt_items)) {
                    $mappedItems = [];
                    foreach ($split->receipt_items as $item_index => $item) {
                        $checked = is_array($item) ? ($item['checkbox'] ?? false) : (bool) $item;
                        $mappedItems[$item_index] = ['checkbox' => (bool) $checked];
                    }
                } else {
                    $mappedItems = $defaultItems; // could be null
                }

                // Set split_index on expense line items so UI can dim others
                if (is_array($mappedItems)) {
                    foreach ($mappedItems as $item_index => $map) {
                        if (! empty($map['checkbox'])) {
                            $li =& $this->lineItemRef($item_index);
                            $li->split_index = $sidx;
                        }
                    }
                }

                $normalized[] = [
                    'id' => $split->id,
                    'amount' => $split->amount,
                    'project_id' => $split->project_id,
                    'distribution_id' => $split->distribution_id,
                    'reimbursment' => $split->reimbursment ?? 'None',
                    'note' => $split->note,
                    'items' => $mappedItems,
                ];
            }

            $this->expense_splits = collect($normalized);
        } elseif ($this->expense_splits instanceof Collection && $this->expense_splits->isNotEmpty()) {
            // already a populated Collection (preserve in-memory splits)
        } elseif (is_array($this->expense_splits) && ! empty($this->expense_splits)) {
            $this->expense_splits = collect($this->expense_splits);
        } elseif (! is_array($this->expense_splits)) {
            if ($this->expense_splits->isEmpty()) {
                $this->expense_splits = collect();
            }
        } else {
            $this->expense_splits = collect();
        }

        // Make sure we have a Collection going forward
        $this->ensureSplitsCollection();

        //if splits isset / comign from Expense.Update form.. otherwire
        if ($this->expense_splits->isEmpty()) {
            $this->expense_splits->push(['amount' => null, 'project_id' => null, 'items' => $defaultItems, 'reimbursment' => 'None']);
            $this->expense_splits->push(['amount' => null, 'project_id' => null, 'items' => $defaultItems, 'reimbursment' => 'None']);
            $this->splits_count = 2;
        } else {
            foreach ($this->expense_splits as $split_index => $split) {
                if (isset($split['items']) && is_array($split['items'])) {
                    foreach ($split['items'] as $item_index => $item) {
                        if (! empty($item['checkbox'])) {
                            $li =& $this->lineItemRef($item_index);
                            $li->split_index = $split_index;
                        }
                    }
                }
            }
            $this->splits_count = $this->expense_splits->count();
        }

        // Ensure indices and assignments are consistent
        $this->reindexSplitsAndRecalc();

        $this->expense_splits = $this->expense_splits->map(function ($split) {
            if (($split['project_id'] ?? null) === null && isset($split['distribution_id'])) {
                $split['project_id'] = 'D:' . $split['distribution_id'];
            }
            return $split;
        });

        $this->getSplitsSumProperty();
        $this->modal('expense_splits_form_modal')->show();
    }

    public function addSplit()
    {
        $receipt = $this->expense?->receipts()->latest()->first();

        if (! is_null($receipt) && ! is_null($receipt->receipt_items['items'] ?? null)) {
            $items = [];
            foreach ($this->expense_line_items->items as $item_index => $line_item) {
                $items[$item_index] = ['checkbox' => false];
            }
        } else {
            $items = null;
        }

    $this->ensureSplitsCollection();
        $this->expense_splits->push(['amount' => null, 'project_id' => null, 'items' => $items, 'reimbursment' => 'None']);
        $this->splits_count = $this->splits_count + 1;

    // Keep keys contiguous and assignments unique
    $this->reindexSplitsAndRecalc();
    }

    public function removeSplit($index)
    {
        $split_checked_items = collect($this->expense_splits[$index]['items'])->where('checkbox', true)->keys();
        foreach ($split_checked_items as $item_index) {
            $li =& $this->lineItemRef($item_index);
            $li->split_index = null;
        }

        if (isset($this->expense_splits[$index]['id'])) {
            $split_to_remove = ExpenseSplits::findOrFail($this->expense_splits[$index]['id']);
            $split_to_remove->delete();
        }

    unset($this->expense_splits[$index]);
    $this->splits_count = max(0, $this->splits_count - 1);

    // Reindex and enforce consistent assignments after removal
    $this->reindexSplitsAndRecalc();
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
