<?php

/**
 * Editing a client on the web used to blank its business name: the edit
 * form never loaded business_name, and update() wrote the empty value back.
 */

use App\Livewire\Clients\ClientCreate;
use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Support/t3-fixtures.php';

it('keeps the business name when a client is edited without touching it', function () {
    $vendor = sec3_makeVendor();
    $client = sec3_makeClient($vendor);
    $client->forceFill(['business_name' => 'Kowalski Holdings'])->save();
    $stored = $client->fresh()->business_name;
    test()->actingAs(sec3_makeAdmin($vendor));

    Livewire::test(ClientCreate::class)
        ->call('editClient', $client)
        ->assertSet('form.business_name', $stored)
        ->set('city', 'Northbrook')
        ->call('edit')
        ->assertHasNoErrors();

    $fresh = Client::findOrFail($client->id);
    expect($fresh->business_name)->toBe($stored)
        ->and($fresh->city)->toBe('Northbrook');
});

it('still saves a changed business name', function () {
    $vendor = sec3_makeVendor();
    $client = sec3_makeClient($vendor);
    test()->actingAs(sec3_makeAdmin($vendor));

    Livewire::test(ClientCreate::class)
        ->call('editClient', $client)
        ->set('form.business_name', 'New Name')
        ->call('edit')
        ->assertHasNoErrors();

    expect(Client::findOrFail($client->id)->business_name)->toBe('New Name');
});
