<?php

namespace App\Livewire\Vendors;

use App\Models\EstimateSubDocument;
use App\Models\Vendor;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * The estimates and invoices a sub has sent us, as compared on our estimates
 * (EstimateSubDocumentCompare): each document with every line the sub
 * listed, the sub's price, and the catalog item it was matched to — the
 * sub's price and scope history, on the sub's own page.
 */
class VendorSubDocuments extends Component
{
    use AuthorizesRequests;

    public Vendor $vendor;

    public function mount(Vendor $vendor): void
    {
        $this->authorize('update', $vendor);

        $this->vendor = $vendor;
    }

    /** @return Collection<int, EstimateSubDocument> */
    #[Computed]
    public function documents(): Collection
    {
        return EstimateSubDocument::query()
            ->with(['items.lineItem:id,name', 'estimate.project:id,project_name,address'])
            ->where('sub_vendor_id', $this->vendor->id)
            ->whereIn('status', [EstimateSubDocument::STATUS_MATCHED, EstimateSubDocument::STATUS_APPLIED])
            ->latest('id')
            ->limit(25)
            ->get();
    }

    public function render(): View
    {
        return view('livewire.vendors.sub-documents');
    }

    public function placeholder(): View
    {
        return view('livewire.vendors.sub-documents-placeholder');
    }
}
