<?php

use App\Livewire\Vendors\VendorOptions;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * The phone-system settings render only for vendor 1 (the company whose
 * Telnyx numbers the line uses), so these tests run against it.
 */
function ringOrder_phoneVendor(): Vendor
{
    $vendor = Vendor::find(1) ?? Vendor::query()->forceCreate(['id' => 1, 'business_name' => 'GS Construction']);
    $vendor->forceFill(['registration' => ['registered' => true], 'state' => 'IL'])->save();

    return $vendor;
}

function ringOrder_admin(Vendor $vendor, string $firstName): User
{
    $user = new User();
    $user->forceFill([
        'first_name' => $firstName,
        'last_name' => 'Admin',
        'email' => 'ring-order-'.strtolower($firstName).'-'.uniqid().'@example.test',
        'cell_phone' => fake()->unique()->numerify('224555####'),
        'primary_vendor_id' => $vendor->id,
    ]);
    $user->save();
    $vendor->users()->attach($user->id, ['role_id' => 1]);

    return $user;
}

it('saves call recipients in the order the list shows, which is the ring order', function () {
    $vendor = ringOrder_phoneVendor();
    $patryk = ringOrder_admin($vendor, 'Patryk');
    $greg = ringOrder_admin($vendor, 'Greg');

    // Ticked Greg first, then Patryk: the stored order still follows the list.
    Livewire::actingAs($patryk)
        ->test(VendorOptions::class)
        ->set('call_recipients', [(string) $greg->id, (string) $patryk->id])
        ->call('save')
        ->assertHasNoErrors();

    expect(data_get($vendor->fresh()->options, 'call_recipients'))->toBe([$patryk->id, $greg->id]);
});

it('explains the ring cascade next to the recipient list', function () {
    $vendor = ringOrder_phoneVendor();
    $admin = ringOrder_admin($vendor, 'Patryk');

    Livewire::actingAs($admin)
        ->test(VendorOptions::class)
        ->assertSee('rings after 20 seconds without an answer');
});
