<?php

namespace App\Livewire\Vendors;

use App\Models\Vendor;
use Livewire\Attributes\Title;
use Livewire\Component;

class VendorShow extends Component
{
    public Vendor $vendor;

    protected $listeners = ['refreshComponent' => '$refresh'];

    public function mount()
    {
        // Remove the direct check and use policy instead
        if (!auth()->user()->can('view', $this->vendor)) {
            return redirect()->route('dashboard');
        }
    }

    #[Title('Vendor')]
    public function render()
    {
        return view('livewire.vendors.show');
    }
}
