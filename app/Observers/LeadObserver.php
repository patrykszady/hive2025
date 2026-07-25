<?php

namespace App\Observers;

use App\Models\AppNotification;
use App\Models\Lead;
use App\Models\Vendor;

class LeadObserver
{
    /**
     * Surface every new lead in /notifications for the owning vendor's admins —
     * leads arrive via webhooks (Angi, website) as well as the UI, so this is
     * the one place that catches them all.
     */
    public function created(Lead $lead): void
    {
        $vendor = Vendor::withoutGlobalScopes()->find($lead->belongs_to_vendor_id);

        if (! $vendor) {
            return;
        }

        $admins = $vendor->users()->wherePivot('role_id', 1)->get();

        if ($admins->isEmpty()) {
            return;
        }

        $name = trim((string) ($lead->lead_data['name'] ?? '')) ?: 'Unknown contact';
        $origin = $lead->origin ? " via {$lead->origin}" : '';
        $address = trim((string) ($lead->lead_data['address'] ?? ''));

        foreach ($admins as $admin) {
            AppNotification::create([
                'user_id' => $admin->id,
                'type' => 'lead_created',
                'title' => "New Lead: {$name}",
                'body' => trim("New lead{$origin}.".($address !== '' ? " {$address}" : '')),
                'action_url' => route('leads.index'),
                'data' => [
                    'lead_id' => $lead->id,
                ],
            ]);
        }
    }
}
