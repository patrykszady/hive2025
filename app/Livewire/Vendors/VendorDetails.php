<?php

namespace App\Livewire\Vendors;

use App\Models\Vendor;
use Livewire\Component;

class VendorDetails extends Component
{
    public Vendor $vendor;

    //im suign $view for this in the app so change?
    public $registration = false;

    protected $listeners = ['refreshComponent' => '$refresh'];

    // public function refresh()
    // {
    //     $this->registration = false;
    //     $this->render();
    // }

    public function render()
    {
        return view('livewire.vendors.vendor-details');
    }
}
