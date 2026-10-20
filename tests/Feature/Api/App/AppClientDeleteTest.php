<?php

use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['scout.driver' => null]);
});

/** @return array{vendor: Vendor, user: User} */
function appJobsClientDeleteTeamUser(int $role = 1): array
{
    $vendor = Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1, 'business_name' => 'GS Construction']);
    $user = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $vendor->users()->attach($user->id, ['role_id' => $role]);

    return compact('vendor', 'user');
}

function appJobsClientDeleteFixture(Vendor $vendor, array $overrides = []): Client
{
    $client = Client::factory()->create($overrides);
    $client->vendors()->attach($vendor->id);

    return $client;
}

it('needs a token to delete a client', function () {
    $this->deleteJson('/api/app/v1/clients/1')->assertUnauthorized();
});

it('lets an Admin delete a client with no projects, like ClientCreate::deleteClient()', function () {
    ['vendor' => $vendor, 'user' => $admin] = appJobsClientDeleteTeamUser();
    Sanctum::actingAs($admin, ['app']);

    $client = appJobsClientDeleteFixture($vendor);
    $person = User::factory()->create(['cell_phone' => '2245550001', 'primary_vendor_id' => null]);
    $client->users()->attach($person->id);

    $this->deleteJson("/api/app/v1/clients/{$client->id}")
        ->assertOk()
        ->assertJsonPath('data.deleted', true);

    expect(Client::withoutGlobalScopes()->find($client->id))->toBeNull()
        // The orphaned contact person (no other client/vendor) is deleted too.
        ->and(User::find($person->id))->toBeNull();
});

it('keeps a contact person who belongs to another client or a vendor', function () {
    ['vendor' => $vendor, 'user' => $admin] = appJobsClientDeleteTeamUser();
    Sanctum::actingAs($admin, ['app']);

    $client = appJobsClientDeleteFixture($vendor);
    $sharedClient = appJobsClientDeleteFixture($vendor, ['business_name' => 'Still Around']);
    $person = User::factory()->create(['cell_phone' => '2245550002', 'primary_vendor_id' => null]);
    $client->users()->attach($person->id);
    $sharedClient->users()->attach($person->id);

    $this->deleteJson("/api/app/v1/clients/{$client->id}")->assertOk();

    expect(User::find($person->id))->not->toBeNull();
});

it('blocks a Member, like ClientPolicy::delete\'s Admin-only gate', function () {
    ['vendor' => $vendor, 'user' => $member] = appJobsClientDeleteTeamUser(2);
    Sanctum::actingAs($member, ['app']);

    $client = appJobsClientDeleteFixture($vendor);

    $this->deleteJson("/api/app/v1/clients/{$client->id}")->assertForbidden();

    expect(Client::withoutGlobalScopes()->find($client->id))->not->toBeNull();
});

it('refuses to delete a client that has projects, like ClientPolicy::delete', function () {
    ['vendor' => $vendor, 'user' => $admin] = appJobsClientDeleteTeamUser();
    Sanctum::actingAs($admin, ['app']);

    $client = appJobsClientDeleteFixture($vendor);
    $project = Project::withoutEvents(fn () => Project::create([
        'project_name' => 'Keeps the client alive',
        'client_id' => $client->id,
        'address' => '1 Oak St', 'city' => 'Chicago', 'state' => 'IL', 'zip_code' => '60601',
        'belongs_to_vendor_id' => $vendor->id,
    ]));
    $project->vendors()->attach($vendor->id, ['client_id' => $client->id]);

    $this->deleteJson("/api/app/v1/clients/{$client->id}")->assertForbidden();

    expect(Client::withoutGlobalScopes()->find($client->id))->not->toBeNull();
});

it('403s deleting a client from another vendor (ClientScope hides it)', function () {
    ['user' => $admin] = appJobsClientDeleteTeamUser();
    Sanctum::actingAs($admin, ['app']);

    $foreign = Client::factory()->create();

    $this->deleteJson("/api/app/v1/clients/{$foreign->id}")->assertForbidden();
});
