<?php

namespace App\Livewire\Vendors;

use App\Models\Category;
use App\Models\Expense;
use App\Models\Vendor;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
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
        // No eager-loaded 'expenses' here on purpose — that dehydrated every
        // expense row for every retail vendor into the page (17MB). The
        // category checkboxes only need per-category counts, computed in SQL
        // by expenseCategoryCounts() below.
        $this->vendors =
            Vendor::where('business_type', 'Retail')
                ->orderBy('created_at', 'DESC')
                ->get();
    }

    /**
     * Category lookup built once instead of a Collection::find() call (a
     * linear scan) per row in the blade.
     *
     * @return Collection<int, Category>
     */
    #[Computed]
    public function categoryMap(): Collection
    {
        return collect($this->categories)->keyBy('id');
    }

    /**
     * Expense counts grouped by vendor and category, aggregated in SQL so
     * the page never loads (or dehydrates) the underlying expense rows.
     *
     * @return array<int, array<int|string, int>>
     */
    #[Computed]
    public function expenseCategoryCounts(): array
    {
        $vendorIds = collect($this->vendors)->pluck('id');

        if ($vendorIds->isEmpty()) {
            return [];
        }

        return Expense::query()
            ->selectRaw('vendor_id, category_id, count(*) as aggregate')
            ->whereIn('vendor_id', $vendorIds)
            ->groupBy('vendor_id', 'category_id')
            ->get()
            ->groupBy('vendor_id')
            ->map(fn (Collection $rows) => $rows->pluck('aggregate', 'category_id')->all())
            ->all();
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

                    $expenses = Expense::where('vendor_id', $vendor->id)
                        ->where('category_id', $category_id)
                        ->get();

                    foreach ($expenses as $expense) {
                        $expense->timestamps = false;
                        $expense->category_id = $this->vendors[$vendor_index]->category_id;
                        $expense->save();
                    }
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
