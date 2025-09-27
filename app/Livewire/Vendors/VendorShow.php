<?php

namespace App\Livewire\Vendors;

use App\Models\Vendor;
use Livewire\Attributes\Title;
use Livewire\Component;

class VendorShow extends Component
{
    public Vendor $vendor;

    protected $listeners = ['refreshComponent' => '$refresh'];
    // Redirect of own vendor handled by dedicated middleware; policy covers access.

    #[Title('Vendor')]
    public function render()
    {
        return view('livewire.vendors.show');
    }
}
