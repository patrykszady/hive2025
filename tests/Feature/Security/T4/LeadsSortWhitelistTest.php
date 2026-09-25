<?php

use App\Livewire\Leads\LeadsIndex;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function sec4_leadsSortFixture(): User
{
    $vendor = Vendor::factory()->create();
    $vendor->forceFill(['business_type' => 'LLC', 'registration' => ['registered' => true]])->save();
    $admin = User::factory()->create();
    $admin->primary_vendor_id = $vendor->id;
    $admin->registration = ['registered' => true];
    $admin->save();
    $vendor->users()->attach($admin->id, ['role_id' => 1]);

    return $admin;
}

it('refuses an unlisted sort column on the leads index', function () {
    $admin = sec4_leadsSortFixture();

    Livewire::actingAs($admin)
        ->test(LeadsIndex::class)
        ->call('sort', 'notes') // free-text column, not on the whitelist
        ->assertSet('sortBy', 'date');
});

it('still sorts by a whitelisted column', function () {
    $admin = sec4_leadsSortFixture();

    Livewire::actingAs($admin)
        ->test(LeadsIndex::class)
        ->call('sort', 'origin')
        ->assertSet('sortBy', 'origin');
});
