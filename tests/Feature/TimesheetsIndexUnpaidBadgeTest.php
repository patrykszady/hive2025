<?php

use App\Livewire\Timesheets\TimesheetsIndex;
use App\Models\Hour;
use App\Models\Timesheet;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    // The Timesheets index searches via Scout/Meilisearch. The collection
    // engine reads from the database (respecting global scopes) so tests
    // exercise the same code path without a running Meilisearch instance.
    config(['scout.driver' => 'collection']);
});

function makeTimesheetsAdmin(): User
{
    $vendor = Vendor::factory()->create();
    $vendor->forceFill([
        'business_type' => 'LLC',
        'registration' => ['registered' => true],
    ])->save();

    $user = User::query()->create([
        'first_name' => 'Test',
        'last_name' => 'Admin',
        'email' => 'timesheets-admin@example.com',
        'cell_phone' => '5551234567',
        'password' => bcrypt('password'),
        'primary_vendor_id' => $vendor->id,
    ]);

    $user->vendors()->attach($vendor->id, [
        'role_id' => 1,
    ]);

    return $user;
}

it('flags an unpaid timesheet group with the Pay badge', function () {
    $admin = makeTimesheetsAdmin();

    Timesheet::forceCreate([
        'date' => '2026-05-11',
        'user_id' => $admin->id,
        'vendor_id' => $admin->primary_vendor_id,
        'hours' => 4,
        'amount' => 200,
        'hourly' => 50,
        'check_id' => null,
        'created_by_user_id' => $admin->id,
    ]);

    Livewire::actingAs($admin)
        ->test(TimesheetsIndex::class)
        ->call('$refresh')
        ->assertSee('Pay');
});

it('flags a paid timesheet group with the Paid badge', function () {
    $admin = makeTimesheetsAdmin();

    Timesheet::forceCreate([
        'date' => '2026-05-11',
        'user_id' => $admin->id,
        'vendor_id' => $admin->primary_vendor_id,
        'hours' => 4,
        'amount' => 200,
        'hourly' => 50,
        'check_id' => 9999,
        'created_by_user_id' => $admin->id,
    ]);

    Livewire::actingAs($admin)
        ->test(TimesheetsIndex::class)
        ->call('$refresh')
        ->assertSee('Paid');
});

it('flags a reimbursed timesheet group with the Paid By badge', function () {
    $admin = makeTimesheetsAdmin();

    $payer = User::query()->create([
        'first_name' => 'Robert',
        'last_name' => 'Payer',
        'email' => 'timesheets-payer@example.com',
        'cell_phone' => '5557776666',
        'password' => bcrypt('password'),
        'primary_vendor_id' => $admin->primary_vendor_id,
    ]);
    $payer->vendors()->attach($admin->primary_vendor_id, ['role_id' => 2]);

    Timesheet::forceCreate([
        'date' => '2026-05-11',
        'user_id' => $admin->id,
        'vendor_id' => $admin->primary_vendor_id,
        'hours' => 4,
        'amount' => 200,
        'hourly' => 50,
        'check_id' => null,
        'paid_by' => $payer->id,
        'invoice' => 'INV-1',
        'created_by_user_id' => $admin->id,
    ]);

    Livewire::actingAs($admin)
        ->test(TimesheetsIndex::class)
        ->call('$refresh')
        ->assertSee('Paid By');
});

it('builds Meilisearch filter conditions from the active filters', function () {
    $component = new TimesheetsIndex;
    $component->user_id = 7;
    $component->paid_statuses = ['Unpaid'];

    $method = new ReflectionMethod($component, 'buildFilterConditions');
    $method->setAccessible(true);

    expect($method->invoke($component))->toBe([
        'user_id = 7',
        'is_paid = false',
    ]);
});

it('does not apply an is_paid condition when both statuses are selected', function () {
    $component = new TimesheetsIndex;
    $component->paid_statuses = ['Confirmed', 'Unpaid'];

    $method = new ReflectionMethod($component, 'buildFilterConditions');
    $method->setAccessible(true);

    expect($method->invoke($component))->toBe([]);
});

it('indexes the timesheet with searchable and filterable attributes', function () {
    $admin = makeTimesheetsAdmin();

    $timesheet = Timesheet::forceCreate([
        'date' => '2026-05-11',
        'user_id' => $admin->id,
        'vendor_id' => $admin->primary_vendor_id,
        'project_id' => null,
        'hours' => 4,
        'amount' => 200,
        'hourly' => 50,
        'check_id' => 9999,
        'note' => 'Framing crew',
        'created_by_user_id' => $admin->id,
    ]);

    $document = $timesheet->toSearchableArray();

    expect($document['user_id'])->toBe($admin->id)
        ->and($document['vendor_id'])->toBe((int) $admin->primary_vendor_id)
        ->and($document['project_id'])->toBeNull()
        ->and($document['is_paid'])->toBeTrue()
        ->and($document['amount'])->toBe(200.0)
        ->and($document['hours'])->toBe(4.0)
        ->and($document['note'])->toBe('Framing crew')
        ->and($document['user_name'])->toBe('Test Admin')
        ->and($document['date'])->toBeInt();
});

it('keeps the weekly hours section independent from confirmed-timesheet filters', function () {
    $admin = makeTimesheetsAdmin();

    $coworker = User::query()->create([
        'first_name' => 'Jordan',
        'last_name' => 'Rivera',
        'email' => 'timesheets-coworker@example.com',
        'cell_phone' => '5559998888',
        'password' => bcrypt('password'),
        'primary_vendor_id' => $admin->primary_vendor_id,
    ]);
    $coworker->vendors()->attach($admin->primary_vendor_id, ['role_id' => 2]);

    Hour::forceCreate([
        'date' => '2026-05-11',
        'user_id' => $admin->id,
        'vendor_id' => $admin->primary_vendor_id,
        'project_id' => 1,
        'hours' => 5,
        'created_by_user_id' => $admin->id,
    ]);

    Hour::forceCreate([
        'date' => '2026-05-11',
        'user_id' => $coworker->id,
        'vendor_id' => $admin->primary_vendor_id,
        'project_id' => 1,
        'hours' => 3,
        'created_by_user_id' => $admin->id,
    ]);

    $this->actingAs($admin);

    $component = new TimesheetsIndex;
    $component->mount();

    $weekly = new ReflectionMethod($component, 'weeklyHoursToConfirm');
    $weekly->setAccessible(true);

    // No filter: both employees appear (grouped by first name).
    expect($weekly->invoke($component)->keys()->sort()->values()->all())
        ->toBe(['Jordan', 'Test']);

    // Confirmed-timesheet filters must not affect this section.
    $component->user_id = $admin->id;
    expect($weekly->invoke($component)->keys()->sort()->values()->all())
        ->toBe(['Jordan', 'Test']);
});

