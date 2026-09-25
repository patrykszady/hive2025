<?php

/**
 * EstimateAccept::save() and ::newEstimateBid() had no authorize() call.
 * The component is mounted for client-browsing users too (it sits on the
 * estimate-details island every estimate viewer sees), so a homeowner could
 * rewrite payment schedules, signers, dates and bids on their own estimate —
 * a component meant to be read-only for them (signing happens in
 * EstimateSign). mount() also unconditionally created an "Original Bid" as a
 * side effect of merely opening the page when none existed yet, which would
 * both write data on a client-browsing view and crash outright (no vendor to
 * stamp the bid with).
 */

use App\Livewire\Estimates\EstimateAccept;
use App\Models\Bid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../../Support/t3-fixtures.php';

it('lets an Admin save the payment schedule and add a change-order bid', function () {
    $vendor = sec3_makeVendor();
    $client = sec3_makeClient($vendor);
    $project = sec3_makeProject($vendor, $client);
    $estimate = sec3_makeEstimate($project, $vendor);
    sec3_makeSection($estimate);
    $admin = sec3_makeAdmin($vendor);
    test()->actingAs($admin);

    Livewire::test(EstimateAccept::class, ['estimate' => $estimate])
        ->call('save')
        ->assertHasNoErrors();

    Livewire::test(EstimateAccept::class, ['estimate' => $estimate])
        ->call('newEstimateBid', 0)
        ->assertHasNoErrors();
});

it('does not auto-create an Original Bid for a homeowner viewing their estimate, but does for an Admin', function () {
    $vendor = sec3_makeVendor();
    $client = sec3_makeClient($vendor);
    $project = sec3_makeProject($vendor, $client);
    $estimate = sec3_makeEstimate($project, $vendor);
    $homeowner = sec3_makeClientUser($client);
    test()->actingAs($homeowner);

    Livewire::test(EstimateAccept::class, ['estimate' => $estimate])->assertOk();

    expect(Bid::withoutGlobalScopes()->where('project_id', $project->id)->count())->toBe(0);

    $admin = sec3_makeAdmin($vendor);
    test()->actingAs($admin);

    Livewire::test(EstimateAccept::class, ['estimate' => $estimate])->assertOk();

    expect(Bid::withoutGlobalScopes()->where('project_id', $project->id)->count())->toBe(1);
});

it('refuses a homeowner saving the payment schedule or adding a bid on their own estimate', function () {
    $vendor = sec3_makeVendor();
    $client = sec3_makeClient($vendor);
    $project = sec3_makeProject($vendor, $client);
    $estimate = sec3_makeEstimate($project, $vendor);
    sec3_makeSection($estimate);
    $homeowner = sec3_makeClientUser($client);
    test()->actingAs($homeowner);

    Livewire::test(EstimateAccept::class, ['estimate' => $estimate])
        ->call('save')
        ->assertForbidden();

    Livewire::test(EstimateAccept::class, ['estimate' => $estimate])
        ->call('newEstimateBid', 0)
        ->assertForbidden();
});
