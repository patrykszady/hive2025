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

it('rings in the order set with the arrows, and saves that order', function () {
    $vendor = ringOrder_phoneVendor();
    $patryk = ringOrder_admin($vendor, 'Patryk');
    $greg = ringOrder_admin($vendor, 'Greg');

    $component = Livewire::actingAs($patryk)
        ->test(VendorOptions::class)
        ->set('call_recipients', [(string) $patryk->id, (string) $greg->id])
        ->call('moveRecipientUp', $greg->id);

    expect($component->instance()->orderedRecipientIds())->toBe([$greg->id, $patryk->id]);

    $component->call('save')->assertHasNoErrors();

    expect(data_get($vendor->fresh()->options, 'call_recipients'))->toBe([$greg->id, $patryk->id]);
});

it('ignores arrows past either end and on people who are not ticked', function () {
    $vendor = ringOrder_phoneVendor();
    $patryk = ringOrder_admin($vendor, 'Patryk');
    $greg = ringOrder_admin($vendor, 'Greg');
    $alex = ringOrder_admin($vendor, 'Alex');

    $component = Livewire::actingAs($patryk)
        ->test(VendorOptions::class)
        ->set('call_recipients', [(string) $patryk->id, (string) $greg->id])
        ->call('moveRecipientUp', $patryk->id)
        ->call('moveRecipientDown', $greg->id)
        ->call('moveRecipientUp', $alex->id);

    expect($component->instance()->orderedRecipientIds())->toBe([$patryk->id, $greg->id]);
});

it('adds a newly ticked person at the end of the call order', function () {
    $vendor = ringOrder_phoneVendor();
    $patryk = ringOrder_admin($vendor, 'Patryk');
    $greg = ringOrder_admin($vendor, 'Greg');
    $alex = ringOrder_admin($vendor, 'Alex');

    $component = Livewire::actingAs($patryk)
        ->test(VendorOptions::class)
        ->set('call_recipients', [(string) $greg->id, (string) $patryk->id])
        ->set('call_recipients', [(string) $greg->id, (string) $patryk->id, (string) $alex->id]);

    expect($component->instance()->orderedRecipientIds())->toBe([$greg->id, $patryk->id, $alex->id]);
});

it('lists ticked people first, in call order, with arrows', function () {
    $vendor = ringOrder_phoneVendor();
    $patryk = ringOrder_admin($vendor, 'Patryk');
    $greg = ringOrder_admin($vendor, 'Greg');

    Livewire::actingAs($patryk)
        ->test(VendorOptions::class)
        ->set('call_recipients', [(string) $greg->id, (string) $patryk->id])
        ->assertSeeInOrder(['Greg Admin', 'Patryk Admin'])
        ->assertSee('moveRecipientUp('.$patryk->id.')', false)
        ->assertSee('Use the arrows to change who is called first')
        ->assertSee('after 15 seconds without an answer')
        ->assertDontSee('Seconds to ring each person');
});
