<?php

namespace App\Livewire\Vendors;

use App\Models\Vendor;
use Livewire\Attributes\Title;
use Livewire\Component;

class VendorShow extends Component
{
    public Vendor $vendor;

    public $users = [];

    public $vendor_docs = [];

    protected $listeners = ['refreshComponent' => '$refresh'];

    public function mount()
    {
        //1/4/24 move to another onion layer ... middleware? gates?
        if ($this->vendor->id == auth()->user()->vendor->id) {
            return redirect(route('dashboard'));
        }

        $this->users = $this->vendor->users()->where('is_employed', 1)->get();
    }

    #[Title('Vendor')]
    public function render()
    {
        return view('livewire.vendors.show');
    }
}
