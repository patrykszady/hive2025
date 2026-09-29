<?php

use App\Livewire\Users\UserCreate;
use App\Models\Client;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * @return array{admin: User, client: Client}
 */
function makeAddClientMemberFixture(): array
{
    Http::fake();

    $vendor = Vendor::factory()->create(['business_name' => 'GS Construction']);

    $admin = User::query()->create([
        'first_name' => 'Owner',
        'last_name' => 'Admin',
        'email' => 'owner.add-client-member-'.uniqid().'@example.test',
        'cell_phone' => (string) random_int(2000000000, 9999999999),
        'primary_vendor_id' => $vendor->id,
    ]);
    $vendor->users()->attach($admin->id, ['is_employed' => true, 'role_id' => 1]);

    $client = Client::factory()->create();
    $vendor->clients()->attach($client->id);

    test()->actingAs($admin);

    return ['admin' => $admin, 'client' => $client];
}

it('adds a brand-new person to a client', function (): void {
    ['client' => $client] = makeAddClientMemberFixture();

    Livewire::test(UserCreate::class)
        ->call('newMember', 'client', $client->id)
        ->set('user_cell', '8475550101')
        ->call('user_cell_find')
        ->set('form.first_name', 'New')
        ->set('form.last_name', 'Member')
        ->set('form.email', 'new.member-'.uniqid().'@example.test')
        ->call('save')
        ->assertOk();

    expect($client->users()->where('cell_phone', '8475550101')->exists())->toBeTrue();
});

it('adds an existing person to a client', function (): void {
    ['client' => $client] = makeAddClientMemberFixture();

    $existing = User::query()->create([
        'first_name' => 'Existing',
        'last_name' => 'Person',
        'email' => 'existing.person-'.uniqid().'@example.test',
        'cell_phone' => '8475550102',
    ]);

    Livewire::test(UserCreate::class)
        ->call('newMember', 'client', $client->id)
        ->set('user_cell', '8475550102')
        ->call('user_cell_find')
        ->call('save')
        ->assertOk();

    expect($client->users()->whereKey($existing->id)->exists())->toBeTrue();
});


it('refuses to add a person to a client the company does not serve', function (): void {
    makeAddClientMemberFixture();

    $otherClient = Client::factory()->create();

    Livewire::test(UserCreate::class)
        ->call('newMember', 'client', $otherClient->id)
        ->set('user_cell', '8475550103')
        ->call('user_cell_find')
        ->set('form.first_name', 'Not')
        ->set('form.last_name', 'Allowed')
        ->set('form.email', 'not.allowed-'.uniqid().'@example.test')
        ->call('save')
        ->assertNotFound();

    expect($otherClient->users()->exists())->toBeFalse();
});
