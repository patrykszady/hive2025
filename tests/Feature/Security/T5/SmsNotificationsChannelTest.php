<?php

use App\Models\Client;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function sec5ChannelVendorUser(): User
{
    $vendor = Vendor::factory()->create();

    $user = new User();
    $user->forceFill([
        'first_name' => 'Vendor',
        'last_name' => 'Staff',
        'email' => 'vendor-staff-'.uniqid().'@example.test',
        'cell_phone' => (string) random_int(2000000000, 9999999999),
        'primary_vendor_id' => $vendor->id,
    ]);
    $user->save();
    $vendor->users()->attach($user->id, ['role_id' => 1]);

    return $user;
}

function sec5ChannelClientUser(): User
{
    $client = Client::query()->create(['business_name' => 'Homeowner '.uniqid()]);

    $user = new User();
    $user->forceFill([
        'first_name' => 'Home',
        'last_name' => 'Owner',
        'email' => 'homeowner-'.uniqid().'@example.test',
        'cell_phone' => (string) random_int(2000000000, 9999999999),
        'primary_vendor_id' => null,
    ]);
    $user->save();
    $user->clients()->attach($client->id);

    return $user->fresh();
}

it('refuses a client (homeowner) user the sms.notifications broadcast channel', function () {
    $this->actingAs(sec5ChannelClientUser())
        ->postJson('/broadcasting/auth', [
            'channel_name' => 'private-sms.notifications',
            'socket_id' => '1234.1234',
        ])
        ->assertForbidden();
});

it('lets a vendor staff user subscribe to the sms.notifications broadcast channel', function () {
    $this->actingAs(sec5ChannelVendorUser())
        ->postJson('/broadcasting/auth', [
            'channel_name' => 'private-sms.notifications',
            'socket_id' => '1234.1234',
        ])
        ->assertOk();
});
