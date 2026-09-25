<?php

/**
 * BidCreate::removeChangeOrder and BidForm::store() resolved
 * $this->bids[i]['id'] (a client-controlled array value) via
 * Bid::withoutGlobalScopes()->findOrFail(), reachable regardless of tenant.
 * A same-tenant attacker who legitimately owns a bid modal for their own
 * project could swap in another tenant's bid id to delete or overwrite it.
 */

use App\Livewire\Bids\BidCreate;
use App\Models\Bid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../../Support/t3-fixtures.php';

it('lets an Admin update and remove a change-order bid on their own project', function () {
    $vendor = sec3_makeVendor();
    $client = sec3_makeClient($vendor);
    $project = sec3_makeProject($vendor, $client);
    $bid = sec3_makeBid($project, $vendor, ['amount' => 100]);
    $admin = sec3_makeAdmin($vendor);
    test()->actingAs($admin);

    Livewire::test(BidCreate::class)
        ->call('addBids', $vendor, $project)
        ->set('bids.0.amount', 555)
        ->call('save');

    expect((float) Bid::withoutGlobalScopes()->findOrFail($bid->id)->amount)->toEqual(555.0);

    Livewire::test(BidCreate::class)
        ->call('addBids', $vendor, $project)
        ->call('removeChangeOrder', 0);

    expect(Bid::withoutGlobalScopes()->find($bid->id))->toBeNull();
});

it('refuses a same-tenant attacker who swaps a foreign bid id into $bids[i][id]', function () {
    $vendor = sec3_makeVendor();
    $client = sec3_makeClient($vendor);
    $project = sec3_makeProject($vendor, $client);
    $myBid = sec3_makeBid($project, $vendor, ['amount' => 100]);

    $vendorB = sec3_makeVendor('Sec3 BidB');
    $clientB = sec3_makeClient($vendorB);
    $projectB = sec3_makeProject($vendorB, $clientB);
    $foreignBid = sec3_makeBid($projectB, $vendorB, ['amount' => 900]);

    $admin = sec3_makeAdmin($vendor);
    test()->actingAs($admin);

    Livewire::test(BidCreate::class)
        ->call('addBids', $vendor, $project)
        ->set('bids.0.id', $foreignBid->id)
        ->set('bids.0.amount', 1)
        ->call('save')
        ->assertNotFound();

    expect((float) Bid::withoutGlobalScopes()->findOrFail($foreignBid->id)->amount)->toEqual(900.0);
    expect((float) Bid::withoutGlobalScopes()->findOrFail($myBid->id)->amount)->toEqual(100.0);

    Livewire::test(BidCreate::class)
        ->call('addBids', $vendor, $project)
        ->set('bids.0.id', $foreignBid->id)
        ->call('removeChangeOrder', 0)
        ->assertNotFound();

    expect(Bid::withoutGlobalScopes()->find($foreignBid->id))->not->toBeNull();
});
