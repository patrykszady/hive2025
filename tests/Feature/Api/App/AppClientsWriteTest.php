<?php

use App\Models\Client;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    // No real Meilisearch/Nylas round trips from fixture setup or the
    // create/update flows themselves — same reasoning as AppClientsTest.
    config(['scout.driver' => null]);
});

/**
 * GS Construction is vendor id 1 — pinned explicitly, same reasoning as
 * AppAuthTest's appTeamUser(). $role 1 = Admin, 2 = Member
 * (App\Models\User::roleNames()).
 *
 * @return array{vendor: Vendor, user: User}
 */
function appPeopleClientsTeam(int $role = 1): array
{
    $vendor = Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1, 'business_name' => 'GS Construction', 'business_type' => 'Sub']);
    $user = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $vendor->users()->attach($user->id, ['role_id' => $role, 'is_employed' => 1, 'start_date' => today()->format('Y-m-d')]);

    return compact('vendor', 'user');
}

/** A client linked to $vendor through client_vendor (what ClientScope checks). */
function appPeopleClientFixture(Vendor $vendor, array $overrides = []): Client
{
    $client = Client::factory()->create($overrides);
    $client->vendors()->attach($vendor->id);

    return $client;
}

it('needs an admin to create a client, a member is refused', function () {
    ['user' => $member] = appPeopleClientsTeam(role: 2);
    Sanctum::actingAs($member, ['app']);

    $this->postJson('/api/app/v1/clients', [
        'cell_phone' => '2245551234',
        'first_name' => 'Carrie',
        'last_name' => 'Smith',
        'email' => 'carrie-'.uniqid().'@example.test',
        'business_name' => 'Smith Household',
        'address' => '1 Oak St',
    ])->assertForbidden();
});

it('looks up a phone with no existing account', function () {
    ['user' => $admin] = appPeopleClientsTeam();
    Sanctum::actingAs($admin, ['app']);

    $this->postJson('/api/app/v1/clients/lookup-person', ['cell_phone' => '2245559999'])
        ->assertOk()
        ->assertExactJson(['data' => ['person' => null, 'existing_clients' => []]]);
});

it('looks up a phone already on one of our clients, offering it instead of a duplicate', function () {
    ['vendor' => $vendor, 'user' => $admin] = appPeopleClientsTeam();
    Sanctum::actingAs($admin, ['app']);

    $person = User::factory()->create(['cell_phone' => '2245558888', 'first_name' => 'Carrie']);
    $existing = appPeopleClientFixture($vendor, ['business_name' => 'Smith Household']);
    $existing->users()->attach($person->id);

    $response = $this->postJson('/api/app/v1/clients/lookup-person', ['cell_phone' => '2245558888'])->assertOk();

    $response->assertJsonPath('data.person.id', $person->id)
        ->assertJsonPath('data.existing_clients.0.id', $existing->id);
});

it('creates a new client with its first person, attaching the vendor and saving the referral source', function () {
    ['vendor' => $vendor, 'user' => $admin] = appPeopleClientsTeam();
    Sanctum::actingAs($admin, ['app']);

    $email = 'carrie-'.uniqid().'@example.test';

    $response = $this->postJson('/api/app/v1/clients', [
        'cell_phone' => '2245551111',
        'first_name' => 'Carrie',
        'last_name' => 'Smith',
        'email' => $email,
        'business_name' => 'Smith Household',
        'address' => '1 Oak St',
        'city' => 'Northbrook',
        'state' => 'IL',
        'zip_code' => '60062',
        'source' => 'Referral from Bob',
    ])->assertCreated();

    $clientId = $response->json('data.id');
    $client = Client::findOrFail($clientId);

    expect($client->vendors()->where('vendors.id', $vendor->id)->exists())->toBeTrue();
    expect($client->vendors()->wherePivot('vendor_id', $vendor->id)->first()->pivot->source)->toBe('Referral from Bob');

    $person = User::where('email', $email)->first();
    expect($person)->not->toBeNull()
        ->and($person->cell_phone)->toBe('2245551111')
        ->and($client->users()->where('user_id', $person->id)->exists())->toBeTrue();

    $response->assertJsonPath('data.business_name', 'Smith Household')
        ->assertJsonPath('data.people.0.id', $person->id);
});

it('reuses an existing person found by phone, ignoring the submitted name', function () {
    ['user' => $admin] = appPeopleClientsTeam();
    Sanctum::actingAs($admin, ['app']);

    $existingPerson = User::factory()->create(['cell_phone' => '2245552222', 'first_name' => 'RealName']);

    $response = $this->postJson('/api/app/v1/clients', [
        'cell_phone' => '2245552222',
        'first_name' => 'SomeoneElse',
        'last_name' => 'Typed',
        'email' => 'ignored-'.uniqid().'@example.test',
        'business_name' => 'New Household',
        'address' => '2 Elm St',
    ])->assertCreated();

    expect($existingPerson->refresh()->first_name)->toBe('RealName');
    expect($response->json('data.people.0.id'))->toBe($existingPerson->id);
});

it('picking an existing client (the duplicate-check radio) just hands it back, creating nothing', function () {
    ['vendor' => $vendor, 'user' => $admin] = appPeopleClientsTeam();
    Sanctum::actingAs($admin, ['app']);

    $existing = appPeopleClientFixture($vendor, ['business_name' => 'Smith Household']);
    $countBefore = Client::withoutGlobalScopes()->count();

    $this->postJson('/api/app/v1/clients', ['existing_client_id' => $existing->id])
        ->assertOk()
        ->assertJsonPath('data.id', $existing->id);

    expect(Client::withoutGlobalScopes()->count())->toBe($countBefore);
});

it('422s a brand-new person whose email is already taken by someone with a different phone', function () {
    ['user' => $admin] = appPeopleClientsTeam();
    Sanctum::actingAs($admin, ['app']);

    $taken = User::factory()->create(['email' => 'taken@example.test', 'cell_phone' => '2245553333']);

    $this->postJson('/api/app/v1/clients', [
        'cell_phone' => '2245554444',
        'first_name' => 'New',
        'last_name' => 'Person',
        'email' => 'taken@example.test',
        'business_name' => 'New Household',
        'address' => '3 Pine St',
    ])->assertStatus(422)->assertJsonValidationErrors('email');
});

it('lets an admin edit a client without wiping the business name, and a member is refused', function () {
    ['vendor' => $vendor, 'user' => $admin] = appPeopleClientsTeam();
    ['user' => $member] = appPeopleClientsTeam(role: 2);
    $client = appPeopleClientFixture($vendor, ['business_name' => 'Old Name', 'address' => 'Old Addr']);

    Sanctum::actingAs($member, ['app']);
    $this->putJson('/api/app/v1/clients/'.$client->id, ['business_name' => 'Old Name', 'address' => 'New Addr'])
        ->assertForbidden();

    Sanctum::actingAs($admin, ['app']);
    $this->putJson('/api/app/v1/clients/'.$client->id, [
        'business_name' => 'Old Name',
        'address' => 'New Addr',
        'source' => 'Updated referral',
    ])->assertOk()
        ->assertJsonPath('data.business_name', 'Old Name')
        ->assertJsonPath('data.address.line1', 'New Addr')
        ->assertJsonPath('data.source', 'Updated referral');

    expect($client->refresh()->business_name)->toBe('Old Name');
});

it('refuses to edit a vendor-linked client even for an admin', function () {
    ['vendor' => $vendor, 'user' => $admin] = appPeopleClientsTeam();
    $client = appPeopleClientFixture($vendor, ['business_name' => 'Linked']);
    $client->forceFill(['vendor_id' => 999])->save();

    Sanctum::actingAs($admin, ['app']);
    $this->putJson('/api/app/v1/clients/'.$client->id, ['business_name' => 'Changed'])
        ->assertForbidden();
});

it('adds a new person to an existing client', function () {
    ['vendor' => $vendor, 'user' => $admin] = appPeopleClientsTeam();
    $client = appPeopleClientFixture($vendor, ['business_name' => 'Smith Household']);

    Sanctum::actingAs($admin, ['app']);
    $email = 'new-person-'.uniqid().'@example.test';

    $response = $this->postJson("/api/app/v1/clients/{$client->id}/people", [
        'cell_phone' => '2245556666',
        'first_name' => 'New',
        'last_name' => 'Person',
        'email' => $email,
    ])->assertCreated();

    $person = User::where('email', $email)->firstOrFail();
    expect($client->users()->where('user_id', $person->id)->exists())->toBeTrue();
    expect($response->json('data.id'))->toBe($person->id);
});

it('422s adding a person already on the client, and a member is refused', function () {
    ['vendor' => $vendor, 'user' => $admin] = appPeopleClientsTeam();
    ['user' => $member] = appPeopleClientsTeam(role: 2);
    $client = appPeopleClientFixture($vendor, ['business_name' => 'Smith Household']);
    $person = User::factory()->create(['cell_phone' => '2245557777']);
    $client->users()->attach($person->id);

    Sanctum::actingAs($member, ['app']);
    $this->postJson("/api/app/v1/clients/{$client->id}/people", ['cell_phone' => '2245557777'])
        ->assertForbidden();

    Sanctum::actingAs($admin, ['app']);
    $this->postJson("/api/app/v1/clients/{$client->id}/people", ['cell_phone' => '2245557777'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('cell_phone');
});

it('saves nickname and preferred language for a new client person, and rejects an unlisted language', function () {
    ['user' => $admin] = appPeopleClientsTeam();
    Sanctum::actingAs($admin, ['app']);

    $email = 'carrie-'.uniqid().'@example.test';
    $response = $this->postJson('/api/app/v1/clients', [
        'cell_phone' => '2245551111',
        'first_name' => 'Carrie',
        'last_name' => 'Smith',
        'nickname' => 'Care',
        'preferred_language' => 'Spanish',
        'email' => $email,
        'business_name' => 'Smith Household',
    ])->assertCreated();

    $person = User::where('email', $email)->firstOrFail();
    expect($person->nickname)->toBe('Care')
        ->and($person->preferred_language)->toBe('Spanish')
        ->and($response->json('data.id'))->not->toBeNull();

    $this->postJson('/api/app/v1/clients', [
        'cell_phone' => '2245551122',
        'first_name' => 'Someone',
        'last_name' => 'Else',
        'preferred_language' => 'Klingon',
        'email' => 'someone-'.uniqid().'@example.test',
        'business_name' => 'Nope Household',
    ])->assertStatus(422)->assertJsonValidationErrors('preferred_language');
});

it('saves nickname and preferred language when adding a person to an existing client, and rejects an unlisted language', function () {
    ['vendor' => $vendor, 'user' => $admin] = appPeopleClientsTeam();
    $client = appPeopleClientFixture($vendor, ['business_name' => 'Smith Household']);

    Sanctum::actingAs($admin, ['app']);
    $email = 'new-person-'.uniqid().'@example.test';

    $response = $this->postJson("/api/app/v1/clients/{$client->id}/people", [
        'cell_phone' => '2245556666',
        'first_name' => 'New',
        'last_name' => 'Person',
        'nickname' => 'Newb',
        'preferred_language' => 'Polish',
        'email' => $email,
    ])->assertCreated();

    $person = User::where('email', $email)->firstOrFail();
    expect($person->nickname)->toBe('Newb')
        ->and($person->preferred_language)->toBe('Polish');

    $this->postJson("/api/app/v1/clients/{$client->id}/people", [
        'cell_phone' => '2245556677',
        'first_name' => 'Nope',
        'last_name' => 'Person',
        'preferred_language' => 'Klingon',
        'email' => 'nope-'.uniqid().'@example.test',
    ])->assertStatus(422)->assertJsonValidationErrors('preferred_language');
});

it('refuses to add a person to a vendor-linked client', function () {
    ['vendor' => $vendor, 'user' => $admin] = appPeopleClientsTeam();
    $client = appPeopleClientFixture($vendor, ['business_name' => 'Linked']);
    $client->forceFill(['vendor_id' => 999])->save();

    Sanctum::actingAs($admin, ['app']);
    $this->postJson("/api/app/v1/clients/{$client->id}/people", [
        'cell_phone' => '2245550000',
        'first_name' => 'New',
        'last_name' => 'Person',
        'email' => 'blocked-'.uniqid().'@example.test',
    ])->assertForbidden();
});
