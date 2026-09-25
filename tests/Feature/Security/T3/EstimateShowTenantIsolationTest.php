<?php

/**
 * EstimateShow's section/line-item write methods resolved ids straight off
 * EstimateSection::findOrFail()/EstimateLineItem::findOrFail() (both
 * unscoped models) using ids taken from client-controlled arrays
 * ($this->sections[$i]['id']) or raw method params (sort_sections/
 * sort_line_item). A same-tenant attacker who legitimately owns estimate A
 * could swap in another tenant's section/line-item id and mutate it. None of
 * the write methods checked authorize('update'), so even a homeowner
 * viewing their own estimate could delete/reorder/restore sections.
 *
 * These tests call the component directly (app(EstimateShow::class), set
 * ->estimate, call mount()/the action) the same way
 * tests/Feature/EstimateSendInviteTest.php does — a full Livewire::test()
 * render of this page trips an unrelated island/tag-nesting issue in the
 * test renderer, so the sibling test avoids it too.
 */

use App\Livewire\Estimates\EstimateShow;
use App\Models\EstimateSection;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../../Support/t3-fixtures.php';

function sec3_showComponentFor(\App\Models\Estimate $estimate): EstimateShow
{
    \Flux::shouldReceive('toast')->andReturnNull();

    $component = app(EstimateShow::class);
    $component->estimate = $estimate;
    $component->mount();

    return $component;
}

it('lets a vendor Admin edit, reorder and delete a section on their own estimate', function () {
    $vendor = sec3_makeVendor();
    $client = sec3_makeClient($vendor);
    $project = sec3_makeProject($vendor, $client);
    $estimate = sec3_makeEstimate($project, $vendor);
    $section = sec3_makeSection($estimate, ['name' => 'Original']);
    $admin = sec3_makeAdmin($vendor);
    test()->actingAs($admin);

    $component = sec3_showComponentFor($estimate);
    $component->sections[0]['name'] = 'Renamed';
    $component->sectionUpdate(0);

    expect(EstimateSection::findOrFail($section->id)->name)->toBe('Renamed');

    $component = sec3_showComponentFor($estimate);
    $component->sectionDelete(0);

    expect(EstimateSection::withTrashed()->findOrFail($section->id)->trashed())->toBeTrue();
});

it('refuses a same-tenant attacker who swaps a foreign section id into $sections[i][id]', function () {
    $vendor = sec3_makeVendor();
    $client = sec3_makeClient($vendor);
    $project = sec3_makeProject($vendor, $client);
    $estimate = sec3_makeEstimate($project, $vendor);
    sec3_makeSection($estimate);

    $vendorB = sec3_makeVendor('Sec3 VB');
    $clientB = sec3_makeClient($vendorB);
    $projectB = sec3_makeProject($vendorB, $clientB);
    $estimateB = sec3_makeEstimate($projectB, $vendorB);
    $foreignSection = sec3_makeSection($estimateB, ['name' => 'Foreign']);

    $admin = sec3_makeAdmin($vendor);
    test()->actingAs($admin);

    $component = sec3_showComponentFor($estimate);
    $component->sections[0]['id'] = $foreignSection->id;
    $component->sections[0]['name'] = 'Hijacked';

    expect(fn () => $component->sectionUpdate(0))->toThrow(ModelNotFoundException::class);
    expect(EstimateSection::findOrFail($foreignSection->id)->name)->toBe('Foreign');

    // sort_sections takes the id directly, not through the array.
    $component = sec3_showComponentFor($estimate);
    expect(fn () => $component->sort_sections($foreignSection->id, 0))->toThrow(ModelNotFoundException::class);
});

it('refuses a homeowner mutating a section on their own visible estimate', function () {
    $vendor = sec3_makeVendor();
    $client = sec3_makeClient($vendor);
    $project = sec3_makeProject($vendor, $client);
    $estimate = sec3_makeEstimate($project, $vendor);
    $section = sec3_makeSection($estimate, ['name' => 'Untouched']);
    $homeowner = sec3_makeClientUser($client);
    test()->actingAs($homeowner);

    // Viewing still works (EstimatePolicy::view allows their own project) —
    // mount() itself must not throw.
    $component = sec3_showComponentFor($estimate);
    $component->sections[0]['name'] = 'Homeowner edit';

    expect(fn () => $component->sectionUpdate(0))->toThrow(AuthorizationException::class);
    expect(EstimateSection::findOrFail($section->id)->name)->toBe('Untouched');
});

it('does not auto-create a section for a homeowner viewing a section-less estimate, but does for an Admin', function () {
    $vendor = sec3_makeVendor();
    $client = sec3_makeClient($vendor);
    $project = sec3_makeProject($vendor, $client);
    $estimate = sec3_makeEstimate($project, $vendor);
    $homeowner = sec3_makeClientUser($client);
    test()->actingAs($homeowner);

    sec3_showComponentFor($estimate);

    expect(EstimateSection::where('estimate_id', $estimate->id)->count())->toBe(0);

    $admin = sec3_makeAdmin($vendor);
    test()->actingAs($admin);

    sec3_showComponentFor($estimate);

    expect(EstimateSection::where('estimate_id', $estimate->id)->count())->toBe(1);
});
