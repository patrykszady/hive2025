<?php

namespace App\Livewire\Forms;

use App\Models\Expense;
use App\Models\Vendor;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Validate;
use Livewire\Form;

class VendorCategoriesForm extends Form
{
    use AuthorizesRequests;

    public ?Vendor $vendor;

    public Expense $expense;

    public $vendor_expense_categories = [];

    #[Validate('required')]
    public $vendor_id = null;

    #[Validate('nullable')]
    public $sheets_type = null;

    public function rules()
    {
        return [
            'vendor_expense_categories.*' => 'nullable',
        ];
    }
    // #[Validate('required')]
    // public $vendor_category = NULL;

    public function setVendor(Vendor $vendor)
    {
        $this->vendor = $vendor;
        $this->vendor_id = $vendor->id;
        // $this->vendor_category = $vendor->vendor_categories()->first()->id ?? NULL;
        $this->sheets_type = $vendor->sheets_type ?? null;
    }

    // store AND update, "sync"
    //prevent duplicates
    public function store()
    {
        // dd($this->vendor_expense_categories);
        // $this->authorize('create', TransactionBulkMatch::class);
        $this->validate();
        foreach ($this->vendor_expense_categories as $expense_category_id => $new_expense_category) {
            $expenses = Expense::where('vendor_id', $this->vendor->id)->where('category_id', $expense_category_id);
            $expenses->update(['category_id' => $new_expense_category]);
        }

        // foreach($this->vendor_expense_categories as $expense_category_id => $vendor_category_id){
        //     // dd($vendor_category_id);
        //     $this->vendor->vendor_categories()->attach($vendor_category_id, ['expense_category_id' => $expense_category_id]);
        //     // $this->vendor->vendor_categories()->attach(['vendor_category_id' => $vendor_category_id, 'expense_category_id' => $expense_category_id]);
        // }

        $this->vendor->sheets_type = ! empty($this->sheets_type) ? $this->sheets_type : null;
        $this->vendor->save();
    }
}
