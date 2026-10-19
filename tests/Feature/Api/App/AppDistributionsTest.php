<?php

use App\Models\Client;
use App\Models\Distribution;
use App\Models\Expense;
use App\Models\Project;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['scout.driver' => null]);
});

/**
 * GS Construction is vendor id 1 — pinned explicitly, same reasoning as
 * AppAuthTest's appTeamUser(). $role is the vendor_user pivot's role_id
 * (1 = Admin, 2 = Member) — DistributionPolicy gates everything on it.
 *
 * @return array{vendor: Vendor, user: User}
 */
function appBooksDistributionsTeamUser(int $role = 1): array
{
    $vendor = Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1, 'business_name' => 'GS Construction']);
    $user = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $vendor->users()->attach($user->id, ['role_id' => $role]);

    return compact('vendor', 'user');
}

function appBooksDistribution(Vendor $vendor, array $overrides = []): Distribution
{
    return Distribution::factory()->create(array_merge([
        'vendor_id' => $vendor->id,
        'name' => 'Greg - Home',
    ], $overrides));
}

it('needs a token to list distributions', function () {
    $this->getJson('/api/app/v1/distributions')->assertUnauthorized();
});

it('refuses a Member listing distributions, like the web', function () {
    ['user' => $member] = appBooksDistributionsTeamUser(role: 2);
    Sanctum::actingAs($member, ['app']);

    $this->getJson('/api/app/v1/distributions')->assertForbidden();
});

it('lists an Admin\'s own distributions with their balance, but never another tenant\'s', function () {
    ['vendor' => $vendor, 'user' => $admin] = appBooksDistributionsTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $mine = appBooksDistribution($vendor, ['name' => 'Patryk - Home']);

    $otherVendor = Vendor::factory()->create();
    appBooksDistribution($otherVendor, ['name' => 'Someone Else']);

    $response = $this->getJson('/api/app/v1/distributions')->assertOk();
    $names = collect($response->json('data'))->pluck('name');

    expect($names->all())->toBe(['Patryk - Home'])
        ->and($response->json('data.0.id'))->toBe($mine->id)
        ->and($response->json('data.0.web_path'))->toBe("/distributions/{$mine->id}");
});

it('shows a distribution\'s vendor breakdown for the trailing year', function () {
    ['vendor' => $vendor, 'user' => $admin] = appBooksDistributionsTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $distribution = appBooksDistribution($vendor);

    $client = Client::factory()->create();
    $client->vendors()->attach($vendor->id);
    $project = Project::withoutEvents(fn () => Project::create([
        'project_name' => 'Kitchen Remodel',
        'client_id' => $client->id,
        'address' => '123 Main St',
        'city' => 'Northbrook',
        'state' => 'IL',
        'zip_code' => '60062',
        'belongs_to_vendor_id' => $vendor->id,
    ]));

    $materialsVendor = Vendor::factory()->create(['business_name' => 'Home Depot', 'business_type' => 'Retail']);
    $vendor->vendors()->attach($materialsVendor->id);

    Expense::create([
        'amount' => 400,
        'date' => now()->format('Y-m-d'),
        'project_id' => $project->id,
        'vendor_id' => $materialsVendor->id,
        'distribution_id' => $distribution->id,
        'belongs_to_vendor_id' => $vendor->id,
        'created_by_user_id' => $admin->id,
    ]);

    $response = $this->getJson("/api/app/v1/distributions/{$distribution->id}")->assertOk();

    expect($response->json('data.id'))->toBe($distribution->id)
        ->and((float) $response->json('data.paid'))->toBe(400.0)
        ->and($response->json('data.vendors.0.name'))->toBe('Home Depot')
        ->and((float) $response->json('data.vendors.0.amount'))->toBe(400.0);
});

it('404s a distribution belonging to another tenant', function () {
    ['user' => $admin] = appBooksDistributionsTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $otherVendor = Vendor::factory()->create();
    $otherDistribution = appBooksDistribution($otherVendor);

    $this->getJson("/api/app/v1/distributions/{$otherDistribution->id}")->assertNotFound();
});

it('lists employed, Admin-tier team members with no distribution yet as eligible', function () {
    ['vendor' => $vendor, 'user' => $admin] = appBooksDistributionsTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $eligible = User::factory()->create(['first_name' => 'Andzelina']);
    $vendor->users()->attach($eligible->id, ['role_id' => 1, 'is_employed' => 1]);

    $alreadyHasOne = User::factory()->create(['first_name' => 'Greg']);
    $vendor->users()->attach($alreadyHasOne->id, ['role_id' => 1, 'is_employed' => 1]);
    appBooksDistribution($vendor, ['name' => 'Greg - Home', 'user_id' => $alreadyHasOne->id]);

    $notEmployed = User::factory()->create(['first_name' => 'Contractor']);
    $vendor->users()->attach($notEmployed->id, ['role_id' => 1, 'is_employed' => 0]);

    $response = $this->getJson('/api/app/v1/distributions/eligible-users')->assertOk();
    $ids = collect($response->json('data'))->pluck('id');

    expect($ids->all())->toContain($eligible->id)
        ->and($ids->all())->not->toContain($alreadyHasOne->id)
        ->and($ids->all())->not->toContain($notEmployed->id);
});

it('creates a distribution for an eligible team member', function () {
    ['vendor' => $vendor, 'user' => $admin] = appBooksDistributionsTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $eligible = User::factory()->create(['first_name' => 'Andzelina']);
    $vendor->users()->attach($eligible->id, ['role_id' => 1, 'is_employed' => 1]);

    $response = $this->postJson('/api/app/v1/distributions', [
        'user_id' => $eligible->id,
        'name' => 'Andzelina - Home',
    ])->assertCreated();

    expect($response->json('data.name'))->toBe('Andzelina - Home');

    $created = Distribution::query()->where('name', 'Andzelina - Home')->first();
    expect($created)->not->toBeNull()
        ->and($created->vendor_id)->toBe($vendor->id)
        ->and($created->user_id)->toBe($eligible->id);
});

it('refuses a Member creating a distribution, like the web', function () {
    ['vendor' => $vendor, 'user' => $member] = appBooksDistributionsTeamUser(role: 2);

    $eligible = User::factory()->create();
    $vendor->users()->attach($eligible->id, ['role_id' => 1, 'is_employed' => 1]);

    Sanctum::actingAs($member, ['app']);

    $this->postJson('/api/app/v1/distributions', [
        'user_id' => $eligible->id,
        'name' => 'Should Not Save',
    ])->assertForbidden();
});

it('validates a new distribution\'s name and user', function () {
    ['user' => $admin] = appBooksDistributionsTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $this->postJson('/api/app/v1/distributions', [
        'user_id' => null,
        'name' => 'ab',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['user_id', 'name']);
});
