<?php

namespace App\Livewire\Vendors;

use App\Models\Vendor;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class VendorsIndex extends Component
{
    use AuthorizesRequests, WithPagination;

    #[Url(except: '')]
    public $business_name = '';

    #[Url(except: '')]
    public $vendor_type = 'Sub';

    public $view;

    public $sortBy = 'ytd_expense_sum';
    public $sortDirection = 'desc';

    protected $listeners = ['refreshComponent' => '$refresh'];

    // Ephemeral vendors created this session (not persisted in pagination/search)
    public array $newVendors = [];

    #[Computed]
    public function newVendorIds(): array
    {
        return array_map(fn ($v) => $v['id'] ?? null, $this->newVendors);
    }

    #[Computed]
    public function attachedMap(): array
    {
        // Build a map of vendor_id => attached_at (pivot created_at) for current page
        $ids = [];
        foreach ($this->vendors as $v) {
            $ids[] = $v->id;
        }

        if (empty($ids)) {
            return [];
        }

        return auth()->user()->vendor->vendors()
            ->whereIn('vendors.id', $ids)
            ->pluck('vendors_vendor.created_at', 'vendors.id')
            ->toArray();
    }

    public function getListeners()
    {
        return array_merge($this->listeners, [
            'VendorCreated' => 'prependNewVendor',
        ]);
    }

    public function prependNewVendor(array $vendor): void
    {
        // Avoid duplicates if present from search response
        foreach ($this->newVendors as $v) {
            if (($v['id'] ?? null) === ($vendor['id'] ?? null)) {
                return;
            }
        }

        array_unshift($this->newVendors, $vendor);
    }

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
        // Special-case: sorting by attached_at (pivot created_at) and ytd_expense_sum (tenant-scoped expenses)
        // can't be reliably sourced from the global search index. Fall back to Eloquent.
        if (in_array($this->sortBy, ['attached_at', 'ytd_expense_sum'], true)) {
            $companyId = auth()->user()->vendor->id;

            $q = Vendor::query()
                ->join('vendors_vendor as vv', function ($join) use ($companyId) {
                    $join->on('vv.vendor_id', '=', 'vendors.id')
                        ->where('vv.belongs_to_vendor_id', '=', $companyId);
                })
                ->select('vendors.*')
                ->withSum([
                    'expenses as ytd_expense_sum' => function ($query) {
                        $query->where('date', '>=', today()->subYear());
                    },
                ], 'amount');

            if (!empty($this->business_name)) {
                $q->where('vendors.business_name', 'like', "%{$this->business_name}%");
            }

            if ($this->vendor_type !== 'All') {
                $q->where('vendors.business_type', $this->vendor_type);
            }

            if ($this->sortBy === 'attached_at') {
                $q->orderBy('vv.created_at', $this->sortDirection);
            } else {
                $q->orderBy('ytd_expense_sum', $this->sortDirection);
            }

            return $q->paginate(10);
        }

        // Default: Use Meilisearch for search and sorting
        $query = Vendor::search($this->business_name);

        if ($this->vendor_type !== 'All') {
            $query->where('business_type', $this->vendor_type);
        }

        $query->orderBy($this->sortBy, $this->sortDirection);

        // Use the AppServiceProvider macro that automatically handles search attributes
        return $query->paginateWithSearchData(10);
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
