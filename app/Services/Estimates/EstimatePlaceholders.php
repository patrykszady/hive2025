<?php

namespace App\Services\Estimates;

use App\Models\Estimate;
use Illuminate\Support\Collection;

/**
 * Fills an email template's {{placeholder}} tokens for an estimate — the
 * shared body of App\Livewire\Estimates\EstimateEmail::replacePlaceholders()
 * and the Hive Contractors app's EstimateEmailController, so both surfaces
 * render the same subject/body text.
 */
class EstimatePlaceholders
{
    /**
     * @param  Collection<int, \App\Models\User>|array<int, \App\Models\User>|null  $adminUsers  vendor admin users, to resolve the sender's display name from $fromEmail
     */
    public function replace(string $text, Estimate $estimate, ?string $fromEmail = null, Collection|array|null $adminUsers = null): string
    {
        $clientName = $estimate->client?->business_name
            ? $estimate->client->business_name
            : ($estimate->client?->first_names ?? 'there');
        $clientFirstNames = $estimate->client?->first_names ?? '';
        $clientLastNames = $estimate->client?->last_names ?? '';
        $projectName = $estimate->project->project_name ?? 'your project';
        $projectAddress = $estimate->project->address ?? '';
        $estimateTotal = '$'.number_format($estimate->amount ?? 0, 2);
        $vendorName = $estimate->vendor->name ?? 'our team';
        $shortVendorName = data_get($estimate->vendor->options, 'short_name') ?: $vendorName;

        $senderUser = $adminUsers && $fromEmail
            ? collect($adminUsers)->firstWhere('email', $fromEmail)
            : null;
        $senderFirstName = $senderUser?->first_name ?? (auth()->user()?->first_name ?? '');
        $senderLastName = $senderUser?->last_name ?? (auth()->user()?->last_name ?? '');

        // The signing page URL is auth-protected — no signed URL needed.
        $signingLink = route('estimate.sign', ['estimate' => $estimate->id]);

        return str_replace(
            [
                '{{client_name}}',
                '{{client_first_names}}',
                '{{client_last_names}}',
                '{{project_name}}',
                '{{project_address_1}}',
                '{{estimate_total}}',
                '{{vendor_name}}',
                '{{short_vendor_name}}',
                '{{sender_first_name}}',
                '{{sender_last_name}}',
                '{{signing_link}}',
            ],
            [
                $clientName,
                $clientFirstNames,
                $clientLastNames,
                $projectName,
                $projectAddress,
                $estimateTotal,
                $vendorName,
                $shortVendorName,
                $senderFirstName,
                $senderLastName,
                $signingLink,
            ],
            $text
        );
    }
}
