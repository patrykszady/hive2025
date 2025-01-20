<?php

namespace App\Livewire\Vendors;

use App\Models\Vendor;
use Livewire\Component;

class VendorDetails extends Component
{
    public Vendor $vendor;

    public $registration = false;
    // public $accordian = 'CLOSED';

    //'refreshComponent' => '$refresh',
    protected $listeners = ['refresh'];

    public function refresh()
    {
        $this->registration = false;
        $this->render();
    }

    public function render()
    {
        return view('livewire.vendors.vendor-details');
    }
}
