<?php

namespace App\Livewire\VendorDocs;

use App\Models\Vendor;

use Livewire\Component;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class VendorDocsCard extends Component
{
    use AuthorizesRequests;
    public Vendor $vendor;

    public $vendor_docs = [];
    public $view = false;

    protected $listeners = ['refreshComponent' => '$refresh'];

    public function render()
    {
        $this->authorize('create', VendorDoc::class);
        $this->vendor_docs = $this->vendor->vendor_docs()->orderBy('expiration_date', 'DESC')->with('agent')->get();

        foreach ($this->vendor_docs as $doc) {
            if ($doc->expiration_date <= today()) {
                $this->vendor->expired_docs = true;
            }
        }

        return view('livewire.vendor-docs.card');
    }
}
