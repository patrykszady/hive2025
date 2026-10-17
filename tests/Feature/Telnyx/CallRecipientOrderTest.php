<?php

use App\Livewire\Vendors\PhoneSystemOptions;
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
        ->test(PhoneSystemOptions::class)
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
        ->test(PhoneSystemOptions::class)
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
        ->test(PhoneSystemOptions::class)
        ->set('call_recipients', [(string) $greg->id, (string) $patryk->id])
        ->set('call_recipients', [(string) $greg->id, (string) $patryk->id, (string) $alex->id]);

    expect($component->instance()->orderedRecipientIds())->toBe([$greg->id, $patryk->id, $alex->id]);
});

it('lists ticked people first, in call order, with arrows', function () {
    $vendor = ringOrder_phoneVendor();
    $patryk = ringOrder_admin($vendor, 'Patryk');
    $greg = ringOrder_admin($vendor, 'Greg');

    Livewire::actingAs($patryk)
        ->test(PhoneSystemOptions::class)
        ->set('call_recipients', [(string) $greg->id, (string) $patryk->id])
        ->assertSeeInOrder(['Greg Admin', 'Patryk Admin'])
        ->assertSee('moveRecipientUp('.$patryk->id.')', false)
        ->assertSee('Use the arrows to change who is called first')
        ->assertSee('after 15 seconds without an answer')
        ->assertDontSee('Seconds to ring each person');
});

it('keeps a separate ring order for vendors and subs, starting from the Call Recipients order', function () {
    $vendor = ringOrder_phoneVendor();
    $patryk = ringOrder_admin($vendor, 'Patryk');
    $greg = ringOrder_admin($vendor, 'Greg');

    $component = Livewire::actingAs($patryk)
        ->test(PhoneSystemOptions::class)
        ->set('call_recipients', [(string) $patryk->id, (string) $greg->id])
        ->assertDontSee('moveVendorRecipientUp(', false)
        ->set('vendor_call_order_enabled', true)
        ->assertSee('Different order when a vendor or sub calls');

    expect($component->instance()->orderedVendorRecipientIds())->toBe([$patryk->id, $greg->id]);

    $component->call('moveVendorRecipientUp', $greg->id)
        ->call('save')
        ->assertHasNoErrors();

    $options = $vendor->fresh()->options;
    expect(data_get($options, 'vendor_call_recipients'))->toBe([$greg->id, $patryk->id])
        ->and(data_get($options, 'call_recipients'))->toBe([$patryk->id, $greg->id]);

    // Off again: vendors and subs ring Call Recipients.
    Livewire::actingAs($patryk->fresh())
        ->test(PhoneSystemOptions::class)
        ->assertSet('vendor_call_order_enabled', true)
        ->set('vendor_call_order_enabled', false)
        ->call('save');

    expect(data_get($vendor->fresh()->options, 'vendor_call_recipients'))->toBe([]);
});

it('saves whether vendors and subs ring one after another or everyone at once', function () {
    $vendor = ringOrder_phoneVendor();
    $patryk = ringOrder_admin($vendor, 'Patryk');
    $greg = ringOrder_admin($vendor, 'Greg');

    Livewire::actingAs($patryk)
        ->test(PhoneSystemOptions::class)
        ->set('call_recipients', [(string) $patryk->id, (string) $greg->id])
        ->set('vendor_call_order_enabled', true)
        ->assertSee('One after another')
        ->set('vendor_call_ring_mode', 'all')
        ->assertSee('Everyone ticked rings together')
        ->assertDontSee('moveVendorRecipientUp(', false)
        ->call('save')
        ->assertHasNoErrors();

    expect(data_get($vendor->fresh()->options, 'vendor_call_ring_all'))->toBeTrue();

    Livewire::actingAs($patryk->fresh())
        ->test(PhoneSystemOptions::class)
        ->assertSet('vendor_call_ring_mode', 'all');
});

it('keeps the phone system on its own page and off Options', function () {
    $vendor = ringOrder_phoneVendor();
    $patryk = ringOrder_admin($vendor, 'Patryk');

    expect(route('phone_system.index'))->toEndWith('/phone-system');

    Livewire::actingAs($patryk)->test(PhoneSystemOptions::class)
        ->assertOk()
        ->assertSee('Phone System')
        ->assertSee('Call Recipients')
        ->assertSee('Different order when a vendor or sub calls');

    Livewire::actingAs($patryk->fresh())->test(\App\Livewire\Vendors\VendorOptions::class)
        ->assertOk()
        ->assertSee('Business Hours')
        ->assertDontSee('Call Recipients')
        ->assertDontSee('Voicemail Menu');
});

it('saves the options page without touching the phone settings', function () {
    $vendor = ringOrder_phoneVendor();
    $patryk = ringOrder_admin($vendor, 'Patryk');
    $greg = ringOrder_admin($vendor, 'Greg');

    Livewire::actingAs($patryk)
        ->test(PhoneSystemOptions::class)
        ->set('call_recipients', [(string) $greg->id, (string) $patryk->id])
        ->set('screening_message', 'Caller: {name}.')
        ->call('save')
        ->assertHasNoErrors();

    Livewire::actingAs($patryk->fresh())
        ->test(\App\Livewire\Vendors\VendorOptions::class)
        ->set('short_name', 'GS')
        ->call('save')
        ->assertHasNoErrors();

    $options = $vendor->fresh()->options;
    expect(data_get($options, 'call_recipients'))->toBe([$greg->id, $patryk->id])
        ->and(data_get($options, 'screening_message'))->toBe('Caller: {name}.')
        ->and(data_get($options, 'short_name'))->toBe('GS');
});

it('has no phone system page for any company but the one whose numbers the line uses', function () {
    $other = Vendor::factory()->create();
    $admin = ringOrder_admin($other, 'Other');

    Livewire::actingAs($admin)->test(PhoneSystemOptions::class)->assertStatus(404);
});
