<?php

namespace App\Livewire\Entry;

use App\Models\Client;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Support\Facades\Cache;
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

        $totalOptions = $this->vendors->count() + $this->clients->count();

        if ($totalOptions === 1) {
            $firstVendor = $this->vendors->first();
            if ($firstVendor) {
                $this->vendor_id = $firstVendor->id;
                $this->vendor = $firstVendor;
            } elseif ($firstClient = $this->clients->first()) {
                $this->client_id = $firstClient->id;
            }
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
        // Exclude clients whose vendor_id matches a vendor the user belongs to,
        // since those are accessible via the vendor account, not as a client account.
        $userVendorIds = $this->user->vendors()
            ->withoutGlobalScopes()
            ->pluck('vendors.id')
            ->toArray();

        return $this->user->clients()
            ->withoutGlobalScopes()
            ->where(function ($query) use ($userVendorIds) {
                $query->whereNull('clients.vendor_id')
                    ->orWhereNotIn('clients.vendor_id', $userVendorIds);
            })
            ->get();
    }

    #[Computed]
    public function hasUnregisteredVendors(): bool
    {
        return $this->vendors->contains(function (Vendor $vendor): bool {
            return data_get($vendor, 'registration.registered') !== true;
        });
    }

    public function updatedVendorId($vendor_id): void
    {
        $this->vendor = $this->vendors->where('id', $vendor_id)->first();
        $this->client_id = null;
    }

    public function updatedClientId(): void
    {
        $this->vendor_id = null;
        $this->vendor = null;
    }

    public function saveVendor()
    {
        $this->user->update(['primary_vendor_id' => $this->vendor->id]);

        Cache::forget('sidebar:nav:' . $this->user->id . ':' . $this->vendor->id);

        if (isset($this->vendor->registration['registered'])) {
            return $this->redirect(route('dashboard'));
        } else {
            return $this->redirect(route('vendor_registration', $this->vendor->id));
        }
    }

    public function saveClient()
    {
        $client = $this->clients->where('id', $this->client_id)->first();

        if ($client) {
            Cache::forget('sidebar:nav:' . $this->user->id . ':client');

            return $this->redirect(route('clients.show', $client));
        }
    }

    #[Title('Account Selection')]
    public function render()
    {
        return view('livewire.entry.vendor-selection');
    }
}
