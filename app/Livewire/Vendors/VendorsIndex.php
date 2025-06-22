<?php

namespace App\Livewire\Vendors;

use App\Models\Vendor;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

class VendorsIndex extends Component
{
    use AuthorizesRequests, WithPagination;

    public $business_name = '';

    public $vendor_type = 'Sub';

    public $view;

    public $sortBy = 'ytd_expense_sum';
    public $sortDirection = 'desc';

    protected $queryString = [
        'business_name' => ['except' => ''],
        'vendor_type' => ['except' => ''],
    ];

    protected $listeners = ['refreshComponent' => '$refresh'];

    public function updating($field)
    {
        $this->resetPage();
    }

    public function updated($field)
    {
        if ($field === 'business_name') {
            $this->vendor_type = 'All';
        }
    }

    #[Computed]
    public function vendors()
    {
        // Use Meilisearch for search and sorting
        $query = Vendor::search($this->business_name);

        if ($this->vendor_type !== 'All') {
            $query->where('business_type', $this->vendor_type);
        }

        $query->orderBy($this->sortBy, $this->sortDirection);

        // Use the AppServiceProvider macro that automatically handles search attributes
        return $query->paginateWithSearchData(20);
    }

    public function sort($column)
    {
        if ($this->sortBy === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDirection = 'asc';
        }
    }

    #[Title('Vendors')]
    public function render()
    {
        return view('livewire.vendors.index');
    }
}
