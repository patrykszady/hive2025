<?php

namespace App\Livewire\Vendors;

use App\Models\Vendor;
use Flux;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

class VendorOptions extends Component
{
    use AuthorizesRequests, WithFileUploads;

    public Vendor $vendor;
    public string $short_name = '';
    public $logo = null;

    /**
     * Display-only mirror of $vendor->options['logo'] — Locked because
     * removeLogo()/save() used to delete whatever path this property held,
     * and an unlocked string property is fully client-writable (any path on
     * the public disk, including other companies' files).
     */
    #[Locked]
    public ?string $existing_logo = null;
    public bool $sms_team_enabled = true;
    public bool $sms_client_enabled = true;
    public bool $sms_vendor_enabled = true;

    /** @var array<int> Admin user IDs who must sign contracts by default */
    public array $default_contract_signers = [];

    /** Business hours — drive after-hours call routing and default user notification window. */
    public string $business_hours_start = '07:00';
    public string $business_hours_end = '18:00';
    /** @var array<int> ISO weekdays (1=Mon … 7=Sun) when business is open. */
    public array $business_hours_days = [1, 2, 3, 4, 5];


    /** @var \Illuminate\Support\Collection<\App\Models\User> Available admin users with cell phones */
    public $adminUsersWithPhones;

    /**
     * Derive a PHP timezone identifier from a US state abbreviation.
     */
    public static function timezoneFromState(?string $state): string
    {
        return match (strtoupper((string) $state)) {
            'CT', 'DC', 'DE', 'FL', 'GA', 'IN', 'KY', 'MA', 'MD', 'ME',
            'MI', 'NC', 'NH', 'NJ', 'NY', 'OH', 'PA', 'RI', 'SC', 'VA',
            'VT', 'WV' => 'America/New_York',
            'AL', 'AR', 'IA', 'IL', 'KS', 'LA', 'MN', 'MO', 'MS', 'ND',
            'NE', 'OK', 'SD', 'TN', 'TX', 'WI' => 'America/Chicago',
            'AZ' => 'America/Phoenix',
            'CO', 'ID', 'MT', 'NM', 'UT', 'WY' => 'America/Denver',
            'CA', 'NV', 'OR', 'WA' => 'America/Los_Angeles',
            'AK' => 'America/Anchorage',
            'HI' => 'Pacific/Honolulu',
            default => 'America/Chicago',
        };
    }

    #[Title('Options')]

    public function mount(): void
    {
        $this->authorize('viewOptions', Vendor::class);
        
        $this->vendor = auth()->user()->vendor;
        $this->short_name = $this->vendor->options?->short_name ?? '';
        $this->existing_logo = $this->vendor->options?->logo ?? null;
        $baseSmsEnabled = (bool) data_get($this->vendor->options, 'sms_enabled', true);
        $this->sms_team_enabled = (bool) data_get($this->vendor->options, 'sms_team_enabled', $baseSmsEnabled);
        $this->sms_client_enabled = (bool) data_get($this->vendor->options, 'sms_client_enabled', $baseSmsEnabled);
        $this->sms_vendor_enabled = (bool) data_get($this->vendor->options, 'sms_vendor_enabled', $baseSmsEnabled);

        $this->default_contract_signers = (array) data_get($this->vendor->options, 'default_contract_signers', []);

        $hours = $this->vendor->businessHours();
        $this->business_hours_start = $hours['start'];
        $this->business_hours_end = $hours['end'];
        $this->business_hours_days = $hours['days'];

        $this->adminUsersWithPhones = $this->vendor->getAdminUsersWithCellPhones();
    }

    protected function rules(): array
    {
        return [
            'short_name' => 'nullable|string|max:100',
            'logo' => 'nullable|image|max:10240', // 10MB max
            'sms_team_enabled' => 'boolean',
            'sms_client_enabled' => 'boolean',
            'sms_vendor_enabled' => 'boolean',
            'default_contract_signers' => 'array',
            'default_contract_signers.*' => 'integer|exists:users,id',
            'business_hours_start' => 'required|date_format:H:i',
            'business_hours_end' => 'required|date_format:H:i|after:business_hours_start',
            'business_hours_days' => 'array',
            'business_hours_days.*' => 'integer|between:1,7',
        ];
    }

    public function save(): void
    {
        $this->authorize('viewOptions', Vendor::class);
        $this->validate();

        // Update timezone derived from vendor address state
        $this->vendor->timezone = self::timezoneFromState($this->vendor->state) ?: null;

        // Build options object
        $options = (array) ($this->vendor->options ?? []);
        $options['short_name'] = $this->short_name ?: null;
        $options['sms_team_enabled'] = $this->sms_team_enabled;
        $options['sms_client_enabled'] = $this->sms_client_enabled;
        $options['sms_vendor_enabled'] = $this->sms_vendor_enabled;
        $options['sms_enabled'] = $this->sms_team_enabled && $this->sms_client_enabled && $this->sms_vendor_enabled;

        $options['default_contract_signers'] = array_map('intval', $this->default_contract_signers);
        $options['business_hours_start'] = $this->business_hours_start;
        $options['business_hours_end'] = $this->business_hours_end;
        $options['business_hours_days'] = array_values(array_map('intval', $this->business_hours_days));

        // Handle logo upload
        if ($this->logo) {
            // Delete old logo if exists — re-read the path from the vendor's
            // own saved options rather than trusting the property, even
            // though it is now Locked (defense in depth).
            $oldLogo = data_get($this->vendor->options, 'logo');
            if ($oldLogo && Storage::disk('public')->exists($oldLogo)) {
                Storage::disk('public')->delete($oldLogo);
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

        // Re-read from the vendor's own saved options rather than trusting
        // the property, even though it is now Locked (defense in depth).
        $logoPath = data_get($this->vendor->options, 'logo');
        if ($logoPath && Storage::disk('public')->exists($logoPath)) {
            Storage::disk('public')->delete($logoPath);
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
