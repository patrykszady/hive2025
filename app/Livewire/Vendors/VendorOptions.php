<?php

namespace App\Livewire\Vendors;

use App\Models\Vendor;
use Flux;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

class VendorOptions extends Component
{
    use AuthorizesRequests, WithFileUploads;

    public Vendor $vendor;
    public string $timezone = '';
    public string $short_name = '';
    public $logo = null;
    public ?string $existing_logo = null;

    #[Title('Options')]

    public function mount(): void
    {
        $this->authorize('viewOptions', Vendor::class);
        
        $this->vendor = auth()->user()->vendor;
        $this->timezone = $this->vendor->timezone ?? '';
        $this->short_name = $this->vendor->options?->short_name ?? '';
        $this->existing_logo = $this->vendor->options?->logo ?? null;
    }

    protected function rules(): array
    {
        return [
            'timezone' => 'nullable|string|max:50',
            'short_name' => 'nullable|string|max:100',
            'logo' => 'nullable|image|max:10240', // 10MB max
        ];
    }

    public function save(): void
    {
        $this->authorize('viewOptions', Vendor::class);
        $this->validate();

        // Update timezone on vendor directly
        $this->vendor->timezone = $this->timezone ?: null;

        // Build options object
        $options = (array) ($this->vendor->options ?? []);
        $options['short_name'] = $this->short_name ?: null;

        // Handle logo upload
        if ($this->logo) {
            // Delete old logo if exists
            if ($this->existing_logo && Storage::disk('public')->exists($this->existing_logo)) {
                Storage::disk('public')->delete($this->existing_logo);
            }

            // Store new logo
            $path = $this->logo->store('vendor-logos', 'public');
            $options['logo'] = $path;
            $this->existing_logo = $path;
        }

        $this->vendor->options = $options;
        $this->vendor->save();

        $this->reset('logo');

        Flux::toast(
            variant: 'success',
            heading: 'Options saved',
            text: 'Your vendor options have been updated.',
        );
    }

    public function removeLogo(): void
    {
        $this->authorize('viewOptions', Vendor::class);

        if ($this->existing_logo && Storage::disk('public')->exists($this->existing_logo)) {
            Storage::disk('public')->delete($this->existing_logo);
        }

        $options = (array) ($this->vendor->options ?? []);
        unset($options['logo']);
        $this->vendor->options = $options ?: null;
        $this->vendor->save();

        $this->existing_logo = null;

        Flux::toast(
            variant: 'success',
            heading: 'Logo removed',
            text: 'Your business logo has been removed.',
        );
    }

    public function removePendingLogo(): void
    {
        $this->authorize('viewOptions', Vendor::class);

        if ($this->logo) {
            $this->logo->delete();
        }

        $this->logo = null;
    }

    public function render()
    {
        return view('livewire.vendors.vendor-options');
    }
}
