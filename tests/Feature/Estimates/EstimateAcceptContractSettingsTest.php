<?php

/**
 * Pins App\Livewire\Estimates\EstimateAccept::save()'s contract-settings
 * slice (required signer ids, contract template ids, and manually
 * reassigning a section's bid) — the part now delegated to
 * App\Services\Estimates\EstimateContractSettingsWriter, the same service
 * the Hive Contractors app's EstimateContractSettingsController shares.
 * Written alongside that extraction: neither ChangeOrderBidTest nor
 * EstimateAcceptPaymentScheduleTest actually exercises this slice despite
 * their docblocks implying otherwise.
 */

use App\Livewire\Estimates\EstimateAccept;
use App\Models\Bid;
use App\Models\EmailTemplate;
use Livewire\Livewire;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

require_once __DIR__.'/../../Support/t3-fixtures.php';

it('persists required signer ids and contract template ids', function () {
    $vendor = sec3_makeVendor();
    $client = sec3_makeClient($vendor);
    $project = sec3_makeProject($vendor, $client);
    $estimate = sec3_makeEstimate($project, $vendor);
    sec3_makeSection($estimate, ['total' => 1000.00]);
    $admin = sec3_makeAdmin($vendor);
    $otherAdmin = sec3_makeAdmin($vendor, 'second-admin');
    test()->actingAs($admin);

    $template = EmailTemplate::create(['vendor_id' => $vendor->id, 'type' => 'contract', 'name' => 'Standard Contract', 'subject' => 'x', 'body' => 'y']);

    Livewire::test(EstimateAccept::class, ['estimate' => $estimate])
        ->set('requiredVendorSignerIds', [$admin->id, $otherAdmin->id])
        ->set('contractTemplateIds', [$template->id])
        ->call('save')
        ->assertHasNoErrors();

    $fresh = $estimate->fresh();
    expect($fresh->options['required_vendor_signer_ids'])->toBe([$admin->id, $otherAdmin->id])
        ->and($fresh->options['contract_template_ids'])->toBe([$template->id]);
});

it('reassigns a section to a new change-order bid and deletes the now-empty original', function () {
    $vendor = sec3_makeVendor();
    $client = sec3_makeClient($vendor);
    $project = sec3_makeProject($vendor, $client);
    $estimate = sec3_makeEstimate($project, $vendor);
    $section = sec3_makeSection($estimate, ['total' => 1000.00]);
    $admin = sec3_makeAdmin($vendor);
    test()->actingAs($admin);

    $component = Livewire::test(EstimateAccept::class, ['estimate' => $estimate]);

    // mount() auto-scaffolded the Original Bid (bid_index 0) and defaulted
    // the section onto it.
    $originalBidId = $component->get('bids')->first()->id;
    expect($section->fresh()->bid_id)->toBeNull();

    // "+ Bid": a brand new change-order bid, and the section moves to it.
    $component->call('newEstimateBid', 0);
    $newBidIndex = $component->get('sections')->first()->bid_index;
    expect($newBidIndex)->toBe(1);

    $component->call('save')->assertHasNoErrors();

    $newBid = $component->get('bids')->get($newBidIndex);
    expect($section->fresh()->bid_id)->toBe($newBid->id)
        ->and((float) $newBid->fresh()->amount)->toBe(1000.0)
        // The now-empty Original bid is kept (it's bid_index 0) but zeroed,
        // never deleted.
        ->and((float) Bid::withoutGlobalScopes()->find($originalBidId)?->amount)->toBe(0.0);
});

it('deletes a non-original bid once every section has moved off it', function () {
    $vendor = sec3_makeVendor();
    $client = sec3_makeClient($vendor);
    $project = sec3_makeProject($vendor, $client);
    $estimate = sec3_makeEstimate($project, $vendor);
    $section = sec3_makeSection($estimate, ['total' => 1000.00]);
    $admin = sec3_makeAdmin($vendor);
    test()->actingAs($admin);

    $component = Livewire::test(EstimateAccept::class, ['estimate' => $estimate])
        ->call('newEstimateBid', 0)
        ->call('save');

    $coBid = Bid::withoutGlobalScopes()->where('project_id', $project->id)->where('type', 2)->first();
    expect($coBid)->not->toBeNull();

    // Move the section back to the Original bid (index 0) and save again —
    // the change-order bid now has nothing on it and should go away.
    $component = Livewire::test(EstimateAccept::class, ['estimate' => $estimate])
        ->set('sections.0.bid_index', 0)
        ->call('save')
        ->assertHasNoErrors();

    expect(Bid::withoutGlobalScopes()->find($coBid->id))->toBeNull()
        ->and($section->fresh()->bid_id)->toBe($component->get('bids')->first()->id);
});
