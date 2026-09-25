<?php

/**
 * EstimatesIndex::disableEstimate/removeEstimate/activateEstimate had no
 * authorize() call at all: any authenticated viewer of /estimates —
 * including a homeowner, since EstimatePolicy::viewAny already allows
 * client-browsing users onto the page — could soft-delete, permanently
 * delete or restore any visible estimate. EstimatePolicy::restore and
 * ::forceDelete were also stub methods that always denied, which would have
 * silently broken restore/permanent-delete for legitimate Admins the moment
 * an authorize() call was added, so both had to be implemented for real.
 */

use App\Livewire\Estimates\EstimatesIndex;
use App\Models\Estimate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../../Support/t3-fixtures.php';

it('lets a vendor Admin disable, permanently delete and restore their own estimate', function () {
    $vendor = sec3_makeVendor();
    $client = sec3_makeClient($vendor);
    $project = sec3_makeProject($vendor, $client);
    $estimate = sec3_makeEstimate($project, $vendor);
    $admin = sec3_makeAdmin($vendor);
    test()->actingAs($admin);

    Livewire::test(EstimatesIndex::class)
        ->call('disableEstimate', $estimate)
        ->assertHasNoErrors();

    expect(Estimate::withTrashed()->findOrFail($estimate->id)->trashed())->toBeTrue();

    Livewire::test(EstimatesIndex::class)
        ->call('activateEstimate', $estimate->id)
        ->assertHasNoErrors();

    expect(Estimate::findOrFail($estimate->id)->trashed())->toBeFalse();

    Livewire::test(EstimatesIndex::class)
        ->call('removeEstimate', $estimate->id) // soft delete first
        ->call('removeEstimate', $estimate->id) // already trashed -> force delete
        ->assertHasNoErrors();

    expect(Estimate::withTrashed()->find($estimate->id))->toBeNull();
});

it('refuses a homeowner disabling, permanently deleting or restoring even their own project\'s estimate', function () {
    $vendor = sec3_makeVendor();
    $client = sec3_makeClient($vendor);
    $project = sec3_makeProject($vendor, $client);
    $estimate = sec3_makeEstimate($project, $vendor);
    $homeowner = sec3_makeClientUser($client);
    test()->actingAs($homeowner);

    Livewire::test(EstimatesIndex::class)
        ->call('disableEstimate', $estimate)
        ->assertForbidden();

    expect(Estimate::findOrFail($estimate->id)->trashed())->toBeFalse();

    // Force it into the trashed state directly (bypassing the component)
    // so restore/force-delete can be exercised on a homeowner too.
    $estimate->delete();

    Livewire::test(EstimatesIndex::class)
        ->call('activateEstimate', $estimate->id)
        ->assertForbidden();

    expect(Estimate::withTrashed()->findOrFail($estimate->id)->trashed())->toBeTrue();

    Livewire::test(EstimatesIndex::class)
        ->call('removeEstimate', $estimate->id)
        ->assertForbidden();

    expect(Estimate::withTrashed()->find($estimate->id))->not->toBeNull();
});
