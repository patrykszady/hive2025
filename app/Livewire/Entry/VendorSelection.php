<?php

namespace App\Livewire\Entry;

use App\Models\User;
use App\Models\Vendor;
use Livewire\Attributes\Title;
use Livewire\Attributes\Computed;
use Livewire\Component;

class VendorSelection extends Component
{
    public User $user;
    public Vendor $vendor;

    public $vendor_id = null;

    public function mount()
    {
        $this->user = auth()->user();
        $this->user->update(['primary_vendor_id' => null]);
    }

    #[Computed]
    public function vendors()
    {
        return $this->user->vendors()
            ->whereIn('vendors.business_type', ['Sub'])
            ->wherePivot('is_employed', 1)
            ->withoutGlobalScopes()
            ->orderBy('vendors.business_type')
            ->get();
    }

    #[Computed]
    public function clients()
    {
        return $this->user->clients()->get();
    }

    public function updatedVendorId($vendor_id)
    {
        $this->vendor = $this->vendors->where('id', $vendor_id)->first();
    }

    public function save()
    {
        $this->user->update(['primary_vendor_id' => $this->vendor->id]);

        if ($this->vendor->registration['registered']) {
            return redirect()->route('dashboard');
        } else {
            return redirect()->route('vendor_registration', $this->vendor->id);
        }
    }

    #[Title('Account Selection')]
    public function render()
    {
        return view('livewire.entry.vendor-selection');
    }
}
