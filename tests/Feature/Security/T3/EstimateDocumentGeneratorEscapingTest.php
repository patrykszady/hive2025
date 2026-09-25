<?php

/**
 * EstimateDocumentGenerator::renderContractTemplate() substituted every
 * placeholder — including client_name and project_address, both ultimately
 * user-controlled (a homeowner's own name, an address typed by an admin) —
 * straight into the contract HTML with no escaping. EstimateSign renders the
 * result with {!! !!}, so an attacker-controlled name/address became a
 * stored XSS payload on the signing page and in the generated PDF.
 * payment_schedule is generated HTML and must stay unescaped.
 */

use App\Models\EmailTemplate;
use App\Models\Estimate;
use App\Models\Vendor;
use App\Support\EstimateDocumentGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('escapes every placeholder value except the generated payment schedule', function () {
    $body = '<p>{{client_name}} — {{project_address}} — {{vendor_name}}</p>{{payment_schedule}}';

    $rendered = EstimateDocumentGenerator::renderContractTemplate($body, [
        'client_name' => '<script>alert(document.cookie)</script>',
        'project_address' => '"><img src=x onerror=alert(1)>',
        'vendor_name' => 'Ampersand & Co.',
        'payment_schedule' => '<table><tr><td>Deposit</td><td>$500.00</td></tr></table>',
    ]);

    expect($rendered)
        ->not->toContain('<script>alert(document.cookie)</script>')
        ->toContain('&lt;script&gt;alert(document.cookie)&lt;/script&gt;')
        ->not->toContain('<img src=x onerror=alert(1)>')
        ->toContain('Ampersand &amp; Co.')
        // The generated payment schedule table is intentional markup.
        ->toContain('<table><tr><td>Deposit</td><td>$500.00</td></tr></table>');
});

it('still renders a normal contract for a legitimate estimate', function () {
    $vendor = Vendor::withoutGlobalScopes()->create([
        'business_name' => 'Sec3 Contractors LLC',
        'business_type' => 'Sub',
    ]);

    $template = EmailTemplate::withoutGlobalScopes()->create([
        'vendor_id' => $vendor->id,
        'name' => 'Contract',
        'type' => 'contract',
        'subject' => null,
        'body' => '<p>Client: {{client_name}}</p><p>Address: {{project_address}}</p>',
    ]);

    $estimate = Estimate::withoutGlobalScopes()->create([
        'project_id' => null,
        'belongs_to_vendor_id' => $vendor->id,
        'options' => ['contract_template_ids' => [$template->id]],
    ]);

    $bodies = EstimateDocumentGenerator::contractBodiesForEstimate($estimate, config('app.timezone'));

    expect($bodies)->toHaveCount(1)
        ->and($bodies[0])->toContain('Client: Unknown Client')
        ->and($bodies[0])->toContain('Address: No address on file');
});
