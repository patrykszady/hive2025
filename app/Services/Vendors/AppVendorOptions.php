<?php

namespace App\Services\Vendors;

use App\Livewire\Vendors\VendorOptions;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * The app's Options screen — the same fields and save rules as the web's
 * App\Livewire\Vendors\VendorOptions, reused here rather than forked
 * (timezoneFromState() is called statically on that class, the one piece of
 * non-trivial logic it carries).
 */
class AppVendorOptions
{
    /**
     * @return array{business_name: ?string, short_name: ?string, logo_url: ?string, timezone: string, sms_team_enabled: bool, sms_client_enabled: bool, sms_vendor_enabled: bool, default_contract_signers: list<int>, business_hours_start: string, business_hours_end: string, business_hours_days: list<int>, admin_users: list<array{id: int, name: string, cell_phone: ?string}>}
     */
    public function present(Vendor $vendor): array
    {
        $baseSmsEnabled = (bool) data_get($vendor->options, 'sms_enabled', true);
        $hours = $vendor->businessHours();
        $logo = data_get($vendor->options, 'logo');

        return [
            // The Short Business Name field's placeholder on the web.
            'business_name' => $vendor->business_name,
            'short_name' => $vendor->options?->short_name ?? '',
            'logo_url' => $logo && Storage::disk('public')->exists($logo) ? Storage::disk('public')->url($logo) : null,
            // Read-only, set from the vendor's business address — mirrors
            // VendorOptions' disabled "Set by your business address" field.
            'timezone' => $vendor->timezone ?: 'America/Chicago',
            'sms_team_enabled' => (bool) data_get($vendor->options, 'sms_team_enabled', $baseSmsEnabled),
            'sms_client_enabled' => (bool) data_get($vendor->options, 'sms_client_enabled', $baseSmsEnabled),
            'sms_vendor_enabled' => (bool) data_get($vendor->options, 'sms_vendor_enabled', $baseSmsEnabled),
            'default_contract_signers' => array_values(array_map('intval', (array) data_get($vendor->options, 'default_contract_signers', []))),
            'business_hours_start' => $hours['start'],
            'business_hours_end' => $hours['end'],
            'business_hours_days' => $hours['days'],
            'admin_users' => $this->adminUsers($vendor),
        ];
    }

    /**
     * @return list<array{id: int, name: string, cell_phone: ?string}>
     */
    public function adminUsers(Vendor $vendor): array
    {
        return $vendor->getAdminUsersWithCellPhones()
            ->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => trim($user->first_name.' '.$user->last_name),
                'cell_phone' => $user->cell_phone,
            ])
            ->values()
            ->all();
    }

    /**
     * Saves every field VendorOptions::save() does, EXCEPT the logo (its own
     * upload()/removeLogo() below) — same reasoning VendorOptions itself has
     * for keeping the file input out of the main form rules.
     *
     * @param  array{short_name: ?string, sms_team_enabled: bool, sms_client_enabled: bool, sms_vendor_enabled: bool, default_contract_signers: list<int>, business_hours_start: string, business_hours_end: string, business_hours_days: list<int>}  $data
     */
    public function update(Vendor $vendor, array $data): Vendor
    {
        $vendor->timezone = VendorOptions::timezoneFromState($vendor->state) ?: null;

        $options = (array) ($vendor->options ?? []);
        $options['short_name'] = $data['short_name'] ?: null;
        $options['sms_team_enabled'] = $data['sms_team_enabled'];
        $options['sms_client_enabled'] = $data['sms_client_enabled'];
        $options['sms_vendor_enabled'] = $data['sms_vendor_enabled'];
        $options['sms_enabled'] = $data['sms_team_enabled'] && $data['sms_client_enabled'] && $data['sms_vendor_enabled'];
        $options['default_contract_signers'] = array_map('intval', $data['default_contract_signers']);
        $options['business_hours_start'] = $data['business_hours_start'];
        $options['business_hours_end'] = $data['business_hours_end'];
        $options['business_hours_days'] = array_values(array_map('intval', $data['business_hours_days']));

        $vendor->options = $options;
        $vendor->save();

        return $vendor;
    }

    /** Mirrors VendorOptions::save()'s logo branch exactly (old file deleted, new one stored on the public disk). */
    public function uploadLogo(Vendor $vendor, UploadedFile $logo): Vendor
    {
        $oldLogo = data_get($vendor->options, 'logo');
        if ($oldLogo && Storage::disk('public')->exists($oldLogo)) {
            Storage::disk('public')->delete($oldLogo);
        }

        $path = $logo->store('vendor-logos', 'public');

        $options = (array) ($vendor->options ?? []);
        $options['logo'] = $path;
        $vendor->options = $options;
        $vendor->save();

        return $vendor;
    }

    /** Mirrors VendorOptions::removeLogo(). */
    public function removeLogo(Vendor $vendor): Vendor
    {
        $logoPath = data_get($vendor->options, 'logo');
        if ($logoPath && Storage::disk('public')->exists($logoPath)) {
            Storage::disk('public')->delete($logoPath);
        }

        $options = (array) ($vendor->options ?? []);
        unset($options['logo']);
        $vendor->options = $options ?: null;
        $vendor->save();

        return $vendor;
    }
}
