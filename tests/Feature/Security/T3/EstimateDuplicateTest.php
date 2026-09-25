<?php

/**
 * EstimateDuplicate::duplicateToEstimateModal(EstimateSection $section) took
 * an unscoped model binding (EstimateSection carries no tenant scope of its
 * own), letting an attacker copy another tenant's section, line items and
 * prices into an estimate on their own project. save()/save_section() also
 * trusted a plain client-writable $project_id with no ownership check,
 * letting a fabricated estimate be attached to another tenant's project.
 */

use App\Livewire\Estimates\EstimateDuplicate;
use App\Models\Estimate;
use App\Models\EstimateSection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../../Support/t3-fixtures.php';

it('lets an Admin duplicate a section from their own estimate to another of their own estimates', function () {
    $vendor = sec3_makeVendor();
    $client = sec3_makeClient($vendor);
    $project = sec3_makeProject($vendor, $client);
    $estimate = sec3_makeEstimate($project, $vendor);
    $section = sec3_makeSection($estimate, ['name' => 'Kitchen']);
    $admin = sec3_makeAdmin($vendor);
    test()->actingAs($admin);

    Livewire::test(EstimateDuplicate::class)
        ->call('duplicateToEstimateModal', $section->id)
        ->set('client_id', $client->id)
        ->set('project_id', $project->id)
        ->set('estimate_id', 'new')
        ->call('save_section')
        ->assertHasNoErrors();

    $newEstimate = Estimate::where('project_id', $project->id)->where('id', '!=', $estimate->id)->firstOrFail();
    expect(EstimateSection::where('estimate_id', $newEstimate->id)->where('name', 'like', 'Kitchen%')->exists())->toBeTrue();
});

it('refuses duplicating another tenant\'s section', function () {
    $vendor = sec3_makeVendor();
    $client = sec3_makeClient($vendor);
    $project = sec3_makeProject($vendor, $client);

    $vendorB = sec3_makeVendor('Sec3 DupB');
    $clientB = sec3_makeClient($vendorB);
    $projectB = sec3_makeProject($vendorB, $clientB);
    $estimateB = sec3_makeEstimate($projectB, $vendorB);
    $foreignSection = sec3_makeSection($estimateB, ['name' => 'Confidential Pricing']);

    $admin = sec3_makeAdmin($vendor);
    test()->actingAs($admin);

    Livewire::test(EstimateDuplicate::class)
        ->call('duplicateToEstimateModal', $foreignSection->id)
        ->assertNotFound();

    expect(Estimate::where('project_id', $project->id)->exists())->toBeFalse();
});

it('refuses save()/save_section() when project_id points at another tenant\'s project', function () {
    $vendor = sec3_makeVendor();
    $client = sec3_makeClient($vendor);
    $project = sec3_makeProject($vendor, $client);
    $estimate = sec3_makeEstimate($project, $vendor);
    $section = sec3_makeSection($estimate);

    $vendorB = sec3_makeVendor('Sec3 DupC');
    $clientB = sec3_makeClient($vendorB);
    $foreignProject = sec3_makeProject($vendorB, $clientB);

    $admin = sec3_makeAdmin($vendor);
    test()->actingAs($admin);

    Livewire::test(EstimateDuplicate::class)
        ->call('duplicateToEstimateModal', $section->id)
        ->set('client_id', $client->id)
        ->set('project_id', $foreignProject->id)
        ->set('estimate_id', 'new')
        ->call('save_section')
        ->assertNotFound();

    expect(Estimate::where('project_id', $foreignProject->id)->exists())->toBeFalse();

    Livewire::test(EstimateDuplicate::class)
        ->call('duplicateModal', $estimate)
        ->set('client_id', $client->id)
        ->set('project_id', $foreignProject->id)
        ->call('save')
        ->assertNotFound();

    expect(Estimate::where('project_id', $foreignProject->id)->exists())->toBeFalse();
});
