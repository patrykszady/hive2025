<?php

/**
 * ClientCreate::addUser looked up a user's clients with
 * $user->clients()->withoutGlobalScopes(), showing another tenant's private
 * client records (business name, address, linked vendors) to anyone who
 * searched by that user's phone/email. save()/edit()/add_user_to_client()
 * had no authorize() call at all, so a Member or homeowner could create or
 * edit clients despite the UI hiding those controls from them.
 */

use App\Livewire\Clients\ClientCreate;
use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../../Support/t3-fixtures.php';

it('only shows an Admin the clients a user shares with their OWN vendor, never another tenant\'s', function () {
    $vendor = sec3_makeVendor();
    $client = sec3_makeClient($vendor);

    $vendorB = sec3_makeVendor('Sec3 ClientB');
    $foreignClient = sec3_makeClient($vendorB);

    // A real person who happens to be linked to both — a returning customer.
    $sharedUser = new \App\Models\User();
    $sharedUser->forceFill([
        'first_name' => 'Shared', 'last_name' => 'Person',
        'email' => 'shared.'.uniqid().'@example.test',
        'cell_phone' => fake()->unique()->numerify('224781####'),
        'registration' => ['registered' => true],
    ]);
    $sharedUser->save();
    $sharedUser->clients()->attach([$client->id, $foreignClient->id]);

    $admin = sec3_makeAdmin($vendor);
    test()->actingAs($admin);

    $component = Livewire::test(ClientCreate::class)->call('addUser', $sharedUser, 'NEW');

    $shownIds = $component->instance()->user_clients->keys()->all();
    expect($shownIds)->toContain($client->id)->not->toContain($foreignClient->id);
});

it('refuses a vendor Member creating or editing a client, but lets an Admin', function () {
    $vendor = sec3_makeVendor();
    $client = sec3_makeClient($vendor);
    $member = sec3_makeMember($vendor);
    test()->actingAs($member);

    Livewire::test(ClientCreate::class)
        ->call('editClient', $client)
        ->set('form.business_name', 'Member Edit')
        ->call('edit')
        ->assertForbidden();

    expect(Client::findOrFail($client->id)->business_name)->not->toBe('Member Edit');

    $admin = sec3_makeAdmin($vendor);
    test()->actingAs($admin);

    Livewire::test(ClientCreate::class)
        ->call('editClient', $client)
        ->set('form.business_name', 'Admin Edit')
        ->call('edit')
        ->assertHasNoErrors();

    expect(Client::findOrFail($client->id)->business_name)->toBe('Admin Edit');
});
