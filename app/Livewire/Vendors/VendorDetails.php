<?php

namespace App\Livewire\Vendors;

use App\Models\Vendor;
use Livewire\Component;

class VendorDetails extends Component
{
    public Vendor $vendor;
    public $view = false;
    public $expanded = false;

    protected $listeners = ['refreshComponent' => '$refresh'];

    public function render()
    {
        return view('livewire.vendors.vendor-details');
    }
}
