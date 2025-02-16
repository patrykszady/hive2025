<?php

namespace App\Livewire\Vendors;

use App\Models\Category;
use App\Models\Vendor;
use Livewire\Component;

class VendorSheetsTypeIndex extends Component
{
    public $vendors = [];

    public $categories = [];

    protected $listeners = ['refreshComponent' => '$refresh'];

    protected function rules()
    {
        return [
            'vendors.*.sheets_type' => 'nullable',
            'vendors.*.permanent_category_id' => 'nullable',
            'vendors.*.categories.*' => 'nullable',
            'vendors.*.category_id' => 'nullable',
        ];
    }

    public function mount()
    {
        $this->categories = Category::all();
        $this->vendors =
            Vendor::where('business_type', 'Retail')
                // ->where('id', 8)
                ->with('expenses')
                // ->with(['expenses' => function($query){
                //     $query->get()->groupBy('category_id');
                // }])
                // ->groupBy('expense.category_id')
                ->orderBy('created_at', 'DESC')
                ->get();
    }

    // public function updated($field, $value)
    // {
    //     dd([$field, $value]);
    // }

    // public function updatedVendors($value, $key)
    // {
    //     // dd([$value, $key]);
    //     // $index = substr($key, 0, strpos($key, "."));
    //     // $vendor = $this->vendors[$index];
    //     // $vendor->sheets_type = $value == "" ? NULL : $value;
    //     // $vendor->save();

    // }

    public function save_vendor_categories($vendor_index)
    {
        $vendor = Vendor::find($this->vendors[$vendor_index]->id);
        //vendor sheets_type if isset
        // $vendor = $vendor->except(['categories']);
        if ($this->vendors[$vendor_index]->permanent_category_id == true) {
            // $vendor->updateOnly(['sheets_type' => $vendor->sheets_type]);
            // $vendor->categories = NULL;
            // $vendor = $vendor->makeHidden(['categories']);
            $vendor->category_id = $this->vendors[$vendor_index]->category_id;
            $vendor->save();
        }

        $vendor->sheets_type = $this->vendors[$vendor_index]->sheets_type;
        $vendor->save();
        // $vendor->sheets_type = $vendor->sheets_type;
        // $vendor->update(['sheets_type' => $vendor->sheets_type]);

        if ($this->vendors[$vendor_index]->categories) {
            //foreach vendor->categories where CHECKED change all expenses to that category
            foreach ($this->vendors[$vendor_index]->categories as $category_id => $category) {
                if ($category == true) {
                    if (empty($category_id)) {
                        $category_id = null;
                    }

                    $expenses = $vendor->expenses->where('category_id', $category_id);
                    // dd($expenses);
                    foreach ($expenses as $expense) {
                        $expense->timestamps = false;
                        $expense->category_id = $this->vendors[$vendor_index]->category_id;
                        $expense->save();
                    }
                    // $expenses->each(function($expense, $key) use($vendor) {
                    //     $expense->update(['category_id' => $vendor->category_id]);
                    //     $expense->save();
                    // });

                    //$expenses->timestamps = false;
                    //$expenses->update(['category_id', $vendor->category_id]);
                }
            }
        }

        $this->mount();
        $this->render();

        $this->dispatch('notify',
            type: 'success',
            content: $vendor->name.' Changed'
        );
        // $vendor->sheets_type = $value == "" ? NULL : $value;

        // dd(collect($vendor->categories)->first());
        // dd('in save_vendor_categories');
    }

    public function render()
    {
        // dd($this->vendors);
        return view('livewire.vendors.sheets-type-index');
    }
}
