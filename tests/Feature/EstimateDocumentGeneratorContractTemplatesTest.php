<?php

use App\Models\EmailTemplate;
use App\Models\Estimate;
use App\Models\Vendor;
use App\Support\EstimateDocumentGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders only selected contract templates for an estimate in the selected order', function () {
    $vendor = Vendor::withoutGlobalScopes()->create([
        'business_name' => 'Test Vendor LLC',
        'business_type' => 'Sub',
    ]);

    $defaultTemplate = EmailTemplate::withoutGlobalScopes()->create([
        'vendor_id' => $vendor->id,
        'name' => 'Default Contract',
        'type' => 'contract',
        'subject' => null,
        'body' => '<p>DEFAULT CONTRACT BODY</p>',
    ]);

    $riderTemplate = EmailTemplate::withoutGlobalScopes()->create([
        'vendor_id' => $vendor->id,
        'name' => 'Rider 1',
        'type' => 'contract',
        'subject' => null,
        'body' => '<p>RIDER 1 BODY</p>',
    ]);

    $unselectedTemplate = EmailTemplate::withoutGlobalScopes()->create([
        'vendor_id' => $vendor->id,
        'name' => 'Unselected Contract',
        'type' => 'contract',
        'subject' => null,
        'body' => '<p>UNSELECTED BODY</p>',
    ]);

    $estimate = Estimate::withoutGlobalScopes()->create([
        'project_id' => null,
        'belongs_to_vendor_id' => $vendor->id,
        'options' => [
            'contract_template_ids' => [$defaultTemplate->id, $riderTemplate->id],
        ],
    ]);

    $bodies = EstimateDocumentGenerator::contractBodiesForEstimate($estimate, config('app.timezone'));

    expect($bodies)->toHaveCount(2)
        ->and($bodies[0])->toContain('DEFAULT CONTRACT BODY')
        ->and($bodies[1])->toContain('RIDER 1 BODY')
        ->and(implode(' ', $bodies))->not->toContain('UNSELECTED BODY');
});

it('renders no contract templates when none are selected on the estimate', function () {
    $vendor = Vendor::withoutGlobalScopes()->create([
        'business_name' => 'Test Vendor LLC',
        'business_type' => 'Sub',
    ]);

    EmailTemplate::withoutGlobalScopes()->create([
        'vendor_id' => $vendor->id,
        'name' => 'Default Contract',
        'type' => 'contract',
        'subject' => null,
        'body' => '<p>DEFAULT CONTRACT BODY</p>',
    ]);

    $estimate = Estimate::withoutGlobalScopes()->create([
        'project_id' => null,
        'belongs_to_vendor_id' => $vendor->id,
        'options' => [],
    ]);

    $bodies = EstimateDocumentGenerator::contractBodiesForEstimate($estimate, config('app.timezone'));

    expect($bodies)->toBe([]);
});
