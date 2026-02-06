<?php

namespace App\Livewire\VendorDocs;

use App\Models\Vendor;
use App\Models\VendorDoc;

use Livewire\Attributes\Lazy;
use Livewire\Component;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

#[Lazy]
class VendorDocsCard extends Component
{
    use AuthorizesRequests;
    public Vendor $vendor;

    public $vendor_docs = [];
    public $view = false;

    protected $listeners = ['refreshComponent' => '$refresh'];

    public function placeholder(array $params = []): \Illuminate\Contracts\View\View
    {
        return view('livewire.vendor-docs.placeholder', [
            'expanded' => !($params['view'] ?? false),
        ]);
    }

    public function render()
    {
        $this->authorize('create', VendorDoc::class);
        $docs = $this->vendor->vendor_docs()
            ->whereIn('type', ['general', 'workers'])
            ->orderByDesc('expiration_date')
            ->with('agent')
            ->get();

        $this->vendor_docs = $docs
            ->groupBy('type')
            ->map(fn ($group) => $group->first())
            ->values();

        foreach ($this->vendor_docs as $doc) {
            if ($doc->expiration_date <= today()) {
                $this->vendor->expired_docs = true;
            }
        }

        return view('livewire.vendor-docs.card');
    }
}
