<?php

/**
 * Pins App\Livewire\Estimates\EstimateAccept::save()'s payment-schedule and
 * contract-date behavior (the slice App\Services\Estimates\
 * EstimatePaymentScheduler now shares with the Hive Contractors app's
 * EstimatePaymentScheduleController) BEFORE and after save() was refactored
 * to delegate to that service — bid assignment, signer ids and contract
 * template ids (the rest of save(), left untouched) are exercised by
 * ChangeOrderBidTest and EstimateAcceptAuthorizationTest instead.
 */

use App\Livewire\Estimates\EstimateAccept;
use App\Models\ProjectStatus;
use Livewire\Livewire;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

require_once __DIR__.'/../../Support/t3-fixtures.php';

it('saves a payment schedule and dates, computing the outstanding balance against the original contract', function () {
    $vendor = sec3_makeVendor();
    $client = sec3_makeClient($vendor);
    $project = sec3_makeProject($vendor, $client);
    $estimate = sec3_makeEstimate($project, $vendor);
    sec3_makeSection($estimate, ['total' => 1000.00]);
    $admin = sec3_makeAdmin($vendor);
    test()->actingAs($admin);

    Livewire::test(EstimateAccept::class, ['estimate' => $estimate])
        ->set('payments', collect([
            ['uid' => 'p0', 'description' => 'Deposit', 'amount' => '400'],
            ['uid' => 'p1', 'description' => 'Final Payment', 'amount' => '600'],
        ]))
        ->set('start_date', '2027-01-15')
        ->call('save')
        ->assertHasNoErrors();

    $fresh = $estimate->fresh();
    expect($fresh->options['payments'])->toBe([
        ['description' => 'Deposit', 'amount' => '400'],
        ['description' => 'Final Payment', 'amount' => '600'],
    ])
        ->and($fresh->options['start_date'])->toBe('2027-01-15');
});

it('refuses to save when payments exceed the original contract total', function () {
    $vendor = sec3_makeVendor();
    $client = sec3_makeClient($vendor);
    $project = sec3_makeProject($vendor, $client);
    $estimate = sec3_makeEstimate($project, $vendor);
    sec3_makeSection($estimate, ['total' => 500.00]);
    $admin = sec3_makeAdmin($vendor);
    test()->actingAs($admin);

    Livewire::test(EstimateAccept::class, ['estimate' => $estimate])
        ->set('payments', collect([
            ['uid' => 'p0', 'description' => 'Deposit', 'amount' => '600'],
        ]))
        ->call('save')
        ->assertHasErrors('payments_remaining_error');

    expect($estimate->fresh()->options['payments'] ?? null)->toBeNull();
});

it('refuses duplicate payment descriptions', function () {
    $vendor = sec3_makeVendor();
    $client = sec3_makeClient($vendor);
    $project = sec3_makeProject($vendor, $client);
    $estimate = sec3_makeEstimate($project, $vendor);
    sec3_makeSection($estimate, ['total' => 1000.00]);
    $admin = sec3_makeAdmin($vendor);
    test()->actingAs($admin);

    Livewire::test(EstimateAccept::class, ['estimate' => $estimate])
        ->set('payments', collect([
            ['uid' => 'p0', 'description' => 'Deposit', 'amount' => '400'],
            ['uid' => 'p1', 'description' => 'deposit', 'amount' => '600'],
        ]))
        ->call('save');

    expect($estimate->fresh()->options['payments'] ?? null)->toBeNull();
});

it('auto-transitions the project to Scheduled when a start date is set from Response/Prep', function () {
    $vendor = sec3_makeVendor();
    $client = sec3_makeClient($vendor);
    $project = sec3_makeProject($vendor, $client);
    ProjectStatus::create(['project_id' => $project->id, 'belongs_to_vendor_id' => $vendor->id, 'status_code' => 4, 'start_date' => now()]);
    $estimate = sec3_makeEstimate($project, $vendor);
    sec3_makeSection($estimate, ['total' => 1000.00]);
    $admin = sec3_makeAdmin($vendor);
    test()->actingAs($admin);

    Livewire::test(EstimateAccept::class, ['estimate' => $estimate])
        ->set('start_date', '2027-02-01')
        ->call('save')
        ->assertHasNoErrors();

    $latest = ProjectStatus::withoutGlobalScopes()->where('project_id', $project->id)->latest('id')->first();
    expect($latest->status_code)->toBe(5)
        ->and($latest->start_date->toDateString())->toBe('2027-02-01');
});

it('reschedules an already-Scheduled status row instead of adding a new one', function () {
    $vendor = sec3_makeVendor();
    $client = sec3_makeClient($vendor);
    $project = sec3_makeProject($vendor, $client);
    $scheduled = ProjectStatus::create(['project_id' => $project->id, 'belongs_to_vendor_id' => $vendor->id, 'status_code' => 5, 'start_date' => '2027-01-01']);
    $estimate = sec3_makeEstimate($project, $vendor);
    sec3_makeSection($estimate, ['total' => 1000.00]);
    $admin = sec3_makeAdmin($vendor);
    test()->actingAs($admin);

    Livewire::test(EstimateAccept::class, ['estimate' => $estimate])
        ->set('start_date', '2027-03-10')
        ->call('save')
        ->assertHasNoErrors();

    expect(ProjectStatus::withoutGlobalScopes()->where('project_id', $project->id)->count())->toBe(1)
        ->and($scheduled->fresh()->start_date->toDateString())->toBe('2027-03-10');
});
