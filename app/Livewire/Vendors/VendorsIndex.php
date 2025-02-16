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

    public $sortBy = 'expense_count';

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
        return Vendor::where('business_name', 'like', "%{$this->business_name}%")
            // ->where('business_type', 'like', "%{$this->vendor_type}%")
            ->withCount([
                'expenses',
                'expenses as expense_count' => function ($query) {
                    $query->where('created_at', '>=', today()->subYear());
                },
            ])
            ->when($this->vendor_type === 'All', function ($query, $item) {
                return $query;
            })
            ->when($this->vendor_type !== 'All', function ($query, $item) {
                return $query->where('business_type', 'like', "%{$this->vendor_type}%");
            })
            //as expense count

            // ->with(['expenses' => function ($query) {
            //     $query->where('created_at', '>=', today()->subYear())->count();
            // }])

            //sort by expenses ytd
            ->tap(fn ($query) => $this->sortBy ? $query->orderBy($this->sortBy, $this->sortDirection) : $query)
            ->paginate(20);
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
