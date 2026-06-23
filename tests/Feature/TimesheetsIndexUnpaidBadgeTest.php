<?php

use App\Livewire\Timesheets\TimesheetsIndex;
use App\Models\Timesheet;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

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

it('flags an unpaid timesheet group with the Unpaid badge', function () {
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
        ->assertSee('Unpaid');
});

it('flags a paid timesheet group with the Confirmed badge', function () {
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
        ->assertSee('Confirmed')
        ->assertDontSee('Unpaid');
});
