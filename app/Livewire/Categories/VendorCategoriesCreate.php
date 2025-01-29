<?php

namespace App\Livewire\Categories;

use App\Livewire\Forms\VendorCategoriesForm;
use App\Models\Category;
use App\Models\Expense;
use App\Models\Vendor;
use Livewire\Component;

class VendorCategoriesCreate extends Component
{
    public VendorCategoriesForm $form;

    public Vendor $vendor;

    public $expense_categories = [];

    public $vendor_categories = [];

    public $vendor_expense_categories = [];

    public $showModal = false;

    protected $listeners = ['addCategories'];

    // public function rules()
    // {
    //     return [
    //         'vendor_category' => 'required'
    //     ];
    // }

    public function mount()
    {
        $this->expense_categories = Category::all();
        // dd($this->expense_categories);
    }

    public function addCategories(Vendor $vendor)
    {
        $this->vendor = $vendor;
        $this->form->setVendor($vendor);

        $this->vendor_expense_categories =
            Expense::where('vendor_id', $this->vendor->id)
                ->whereYear('date', '>=', 2023)
                ->with('category')
                ->get()
                ->groupBy('category.friendly_primary')
                ->toBase();

        // dd($this->vendor_expense_categories);
        $this->showModal = true;
    }

    public function save()
    {
        $this->form->store();
        $this->dispatch('refreshComponent')->to('categories.categories-index');

        $this->showModal = false;
        $this->dispatch('notify',
            type: 'success',
            content: 'Vendor Categories Created'
        );
    }

    public function render()
    {
        return view('livewire.categories.vendor-create-form');
    }
}
