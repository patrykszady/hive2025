<?php

namespace App\Livewire\Entry;

use App\Models\Client;
use App\Models\User;
use App\Models\Vendor;
use Livewire\Attributes\Title;
use Livewire\Attributes\Computed;
use Livewire\Component;

class VendorSelection extends Component
{
    public User $user;
    public ?Vendor $vendor = null;

    public $vendor_id = null;
    public $client_id = null;

    public function mount()
    {
        $this->user = auth()->user();
        
        // Don't clear primary_vendor for client-only users
        if (!$this->user->is_client_user) {
            $this->user->update(['primary_vendor_id' => null]);
        }

        $firstVendor = $this->vendors->first();
        if ($firstVendor) {
            $this->vendor_id = $firstVendor->id;
            $this->vendor = $firstVendor;
        }
        
        // Pre-select first client for client users
        $firstClient = $this->clients->first();
        if ($firstClient) {
            $this->client_id = $firstClient->id;
        }
    }

    #[Computed]
    public function vendors()
    {
        return $this->user->vendors()
            // 1099
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

    #[Computed]
    public function hasUnregisteredVendors(): bool
    {
        return $this->vendors->contains(function (Vendor $vendor): bool {
            return data_get($vendor, 'registration.registered') !== true;
        });
    }

    public function updatedVendorId($vendor_id)
    {
        $this->vendor = $this->vendors->where('id', $vendor_id)->first();
    }

    public function save()
    {
        // Handle client selection
        if ($this->client_id && $this->user->is_client_user) {
            $client = $this->clients->where('id', $this->client_id)->first();
            if ($client) {
                return $this->redirect(route('clients.show', $client), navigate: true);
            }
        }
        
        // Handle vendor selection
        $this->user->update(['primary_vendor_id' => $this->vendor->id]);
        if (isset($this->vendor->registration['registered'])) {
            return $this->redirect(route('dashboard'), navigate: true);
        } else {
            return $this->redirect(route('vendor_registration', $this->vendor->id), navigate: true);
        }
    }

    #[Title('Account Selection')]
    public function render()
    {
        return view('livewire.entry.vendor-selection');
    }
}
