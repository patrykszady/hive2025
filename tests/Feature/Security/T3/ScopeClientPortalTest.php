<?php

/**
 * Root cause: EstimateScope, BidScope, ProjectScope, ClientScope and
 * PaymentScope all returned every tenant's rows, unfiltered, for a signed-in
 * homeowner ("client browsing") user. These tests prove a homeowner now only
 * ever sees their own client's projects/estimates/bids/payments, that a
 * cross-tenant homeowner attempt is refused, and that vendor-user scoping is
 * unchanged.
 */

use App\Models\Client;
use App\Models\Estimate;
use App\Models\Payment;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../../Support/t3-fixtures.php';

it('lets a homeowner see their own project, estimate, bid, payment and client', function () {
    $vendor = sec3_makeVendor();
    $client = sec3_makeClient($vendor);
    $project = sec3_makeProject($vendor, $client);
    $estimate = sec3_makeEstimate($project, $vendor);
    $bid = sec3_makeBid($project, $vendor);
    $adminA = sec3_makeAdmin($vendor, 'paya');
    $payment = Payment::withoutGlobalScopes()->create([
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $vendor->id,
        'amount' => 500,
        'date' => now()->toDateString(),
        'created_by_user_id' => $adminA->id,
    ]);

    $homeowner = sec3_makeClientUser($client);
    test()->actingAs($homeowner);

    expect(Client::query()->pluck('id'))->toContain($client->id)
        ->and(Project::query()->pluck('id'))->toContain($project->id)
        ->and(Estimate::query()->pluck('id'))->toContain($estimate->id)
        ->and(\App\Models\Bid::query()->pluck('id'))->toContain($bid->id)
        ->and(Payment::query()->pluck('id'))->toContain($payment->id);
});

it('never lets a homeowner reach another tenant\'s project, estimate, bid, payment or client', function () {
    $vendorA = sec3_makeVendor('Sec3 A');
    $clientA = sec3_makeClient($vendorA);
    $projectA = sec3_makeProject($vendorA, $clientA);
    $estimateA = sec3_makeEstimate($projectA, $vendorA);
    $bidA = sec3_makeBid($projectA, $vendorA);
    $adminA = sec3_makeAdmin($vendorA, 'payb');
    $paymentA = Payment::withoutGlobalScopes()->create([
        'project_id' => $projectA->id,
        'belongs_to_vendor_id' => $vendorA->id,
        'amount' => 750,
        'date' => now()->toDateString(),
        'created_by_user_id' => $adminA->id,
    ]);

    $vendorB = sec3_makeVendor('Sec3 B');
    $clientB = sec3_makeClient($vendorB);
    $homeownerB = sec3_makeClientUser($clientB);
    test()->actingAs($homeownerB);

    expect(Client::query()->pluck('id'))->not->toContain($clientA->id)
        ->and(Project::query()->pluck('id'))->not->toContain($projectA->id)
        ->and(Estimate::query()->pluck('id'))->not->toContain($estimateA->id)
        ->and(\App\Models\Bid::query()->pluck('id'))->not->toContain($bidA->id)
        ->and(Payment::query()->pluck('id'))->not->toContain($paymentA->id);

    // Direct lookups by id 404 the same way (route-model binding relies on this).
    expect(fn () => Estimate::findOrFail($estimateA->id))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
    expect(fn () => Project::findOrFail($projectA->id))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
});

it('gives an authenticated user with no vendor and no client zero rows, not everything', function () {
    $vendor = sec3_makeVendor();
    $client = sec3_makeClient($vendor);
    sec3_makeProject($vendor, $client);
    sec3_makeEstimate(sec3_makeProject($vendor, $client), $vendor);

    // A user with neither a vendor nor a linked client (e.g. mid-invite).
    $orphan = new \App\Models\User();
    $orphan->forceFill([
        'first_name' => 'No', 'last_name' => 'Links',
        'email' => 'orphan.'.uniqid().'@example.test',
        'cell_phone' => fake()->unique()->numerify('224780####'),
        'registration' => ['registered' => true],
    ]);
    $orphan->save();
    test()->actingAs($orphan);

    expect(Client::query()->count())->toBe(0)
        ->and(Project::query()->count())->toBe(0)
        ->and(Estimate::query()->count())->toBe(0);
});

it('keeps vendor-user scoping unchanged: an Admin only sees their own vendor\'s rows', function () {
    $vendorA = sec3_makeVendor('Sec3 VA');
    $clientA = sec3_makeClient($vendorA);
    $projectA = sec3_makeProject($vendorA, $clientA);
    $estimateA = sec3_makeEstimate($projectA, $vendorA);

    $vendorB = sec3_makeVendor('Sec3 VB');
    $clientB = sec3_makeClient($vendorB);
    $projectB = sec3_makeProject($vendorB, $clientB);
    $estimateB = sec3_makeEstimate($projectB, $vendorB);

    $adminA = sec3_makeAdmin($vendorA);
    test()->actingAs($adminA);

    expect(Project::query()->pluck('id'))->toContain($projectA->id)->not->toContain($projectB->id)
        ->and(Estimate::query()->pluck('id'))->toContain($estimateA->id)->not->toContain($estimateB->id)
        ->and(Client::query()->pluck('id'))->toContain($clientA->id)->not->toContain($clientB->id);
});

it('does not leak one homeowner\'s client-dropdown cache entry to a different homeowner', function () {
    $vendorA = sec3_makeVendor('Sec3 CA');
    $clientA = sec3_makeClient($vendorA);
    $homeownerA = sec3_makeClientUser($clientA, 'cachea');

    $vendorB = sec3_makeVendor('Sec3 CB');
    $clientB = sec3_makeClient($vendorB);
    $homeownerB = sec3_makeClientUser($clientB, 'cacheb');

    test()->actingAs($homeownerA);
    $listA = Client::cachedDropdownList();
    expect($listA->pluck('id')->all())->toBe([$clientA->id]);

    test()->actingAs($homeownerB);
    $listB = Client::cachedDropdownList();
    expect($listB->pluck('id')->all())->toBe([$clientB->id]);
});
