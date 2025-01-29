<?php

namespace App\Livewire\Forms;

use App\Models\ExpenseSplit;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Form;

class ExpenseSplitForm extends Form
{
    use AuthorizesRequests;

    // public ?ExpenseSplit $split;

    public $expense_splits = [];

    public function rules()
    {
        return [
            'expense_splits.*.amount' => 'required|numeric|regex:/^-?\d+(\.\d{1,2})?$/',
            'expense_splits.*.project_id' => 'required',
            'expense_splits.*.reimbursment' => 'nullable',
            'expense_splits.*.note' => 'nullable',
            'expense_splits.*.items.*.checkbox' => 'nullable',
        ];
    }

    public function setSplits($splits)
    {
        $this->expense_splits = $splits;
        foreach ($this->expense_splits as $index => $split) {
            if ($split['project_id'] == null && isset($split['distribution_id'])) {
                $this->expense_splits[$index]['project_id'] = 'D:'.$split['distribution_id'];
            }
        }
    }

    // protected $messages =
    // [
    //     'expense_splits.*.amount.regex' => 'Amount format is incorrect. Format is 2145.36. No commas and only two digits after decimal allowed. If amount is under $1.00, use 0.XX',
    // ];

    public function store()
    {
        // $this->authorize('create', Expense::class);
        // dd($this);
        $this->validate();

        return $this->expense_splits;

        //only
        // ExpenseSplit::create($this->all());

        // $this->reset();
    }
}
