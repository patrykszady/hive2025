<?php

namespace App\Livewire\VendorDocs;

use App\Models\Vendor;
use Livewire\Component;

class VendorDocsCard extends Component
{
    public Vendor $vendor;

    public $vendor_docs = [];

    public $view = false;

    protected $listeners = ['refreshComponent' => '$refresh'];

    public function mount()
    {
        //dont show where havent done business with / no checks in the last YTD
        // $this->vendor->fresh();

        //->groupBy('type')->toBase()
        // $this->vendor_docs = $this->vendor->vendor_docs()->orderBy('expiration_date', 'DESC')->with('agent')->get();
        // dd($this->vendor_docs);
        // $doc_types = $this->vendor->vendor_docs()->orderBy('expiration_date', 'DESC')->with('agent')->get()->groupBy('type');

        // foreach($doc_types as $type_certificates)
        // {
        //     if($type_certificates->first()->expiration_date <= today()){
        //         $this->vendor->expired_docs = TRUE;
        //     }
        // }
    }

    public function render()
    {
        $this->vendor_docs = $this->vendor->vendor_docs()->orderBy('expiration_date', 'DESC')->with('agent')->get()->groupBy('type')->toBase();

        foreach ($this->vendor_docs as $type_certificates) {
            if ($type_certificates->first()->expiration_date <= today()) {
                $this->vendor->expired_docs = true;
            }
        }

        return view('livewire.vendor-docs.card');
    }
}
