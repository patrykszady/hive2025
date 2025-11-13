<?php

namespace App\Support;

use App\Models\Estimate;

class EstimateEmailTemplate
{
    public static function defaultBody(Estimate $estimate): string
    {
        $clientName = $estimate->client?->business_name 
            ? $estimate->client->business_name 
            : ($estimate->client?->first_names ?? 'there');
        $projectName = $estimate->project->project_name ?? 'your project';
        $vendorName = $estimate->vendor->name ?? 'our team';

        return <<<HTML
<p>Hi {$clientName},</p>
<p>Attached is the latest estimate for {$projectName}. Please review it at your convenience.</p>
<p>Let us know if you have any questions or if you'd like to schedule a walkthrough.</p>
<p>Thank you,<br>{$vendorName}</p>
HTML;
    }
}
