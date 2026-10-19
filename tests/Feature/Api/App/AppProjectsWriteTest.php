<?php

use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    // No real Meilisearch round trips from fixture setup/Project::create's
    // own searchable() reindex — same reasoning as AppProjectsTest.
    config(['scout.driver' => null]);
});

/**
 * GS Construction is vendor id 1 — pinned explicitly, same reasoning as
 * AppPaymentsTest's appPaymentsTeamUser(). $role is the vendor_user pivot's
 * role_id (1 = Admin, 2 = Member) — ProjectPolicy::create is Admin-only.
 *
 * @return array{vendor: Vendor, user: User}
 */
function appJobsProjectsTeamUser(int $role = 1): array
{
    $vendor = Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1, 'business_name' => 'GS Construction']);
    $user = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $vendor->users()->attach($user->id, ['role_id' => $role]);

    return compact('vendor', 'user');
}

/** A client linked to $vendor through client_vendor (ClientScope). */
function appJobsProjectsClient(Vendor $vendor, array $overrides = []): Client
{
    $client = Client::factory()->create($overrides);
    $client->vendors()->attach($vendor->id);

    return $client;
}

it('needs a token to create a project', function () {
    $this->postJson('/api/app/v1/projects', ['client_id' => 1, 'project_name' => 'X', 'project_existing_address' => 'NEW'])
        ->assertUnauthorized();
});

it('lets an Admin create a project with a new address, with the same side effects as the web', function () {
    ['vendor' => $vendor, 'user' => $user] = appJobsProjectsTeamUser(1);
    Sanctum::actingAs($user, ['app']);

    $client = appJobsProjectsClient($vendor);

    $response = $this->postJson('/api/app/v1/projects', [
        'client_id' => $client->id,
        'project_name' => 'Kitchen Remodel',
        'project_existing_address' => 'NEW',
        'address' => '456 Oak St',
        'city' => 'Glenview',
        'state' => 'IL',
        'zip_code' => '60025',
    ])->assertCreated();

    $projectId = $response->json('data.id');
    $project = Project::query()->findOrFail($projectId);

    expect($project->project_name)->toBe('Kitchen Remodel')
        ->and($project->client_id)->toBe($client->id)
        ->and($project->address)->toBe('456 Oak St')
        ->and($project->belongs_to_vendor_id)->toBe($vendor->id);

    // ProjectObserver::created() side effects: vendor attached + initial
    // Estimate (code 2) status, same as the web's ProjectCreate.
    expect($project->vendors->pluck('id')->all())->toContain($vendor->id);

    $status = ProjectStatus::withoutGlobalScopes()->where('project_id', $project->id)->first();
    expect($status->status_code)->toBe(2);

    $response->assertJsonPath('data.status.code', 2)
        ->assertJsonPath('data.name', 'Kitchen Remodel');
});

it('blocks a Member from creating a project, like the web\'s Admin-only ProjectPolicy::create', function () {
    ['vendor' => $vendor, 'user' => $user] = appJobsProjectsTeamUser(2);
    Sanctum::actingAs($user, ['app']);

    $client = appJobsProjectsClient($vendor);

    $this->postJson('/api/app/v1/projects', [
        'client_id' => $client->id,
        'project_name' => 'Basement Stairs',
        'project_existing_address' => 'NEW',
        'address' => '1 Main St',
        'city' => 'Chicago',
    ])->assertForbidden();

    expect(Project::withoutGlobalScopes()->where('project_name', 'Basement Stairs')->exists())->toBeFalse();
});

it('returns the twin instead of a duplicate on a double-submit, like ProjectForm::store()', function () {
    ['vendor' => $vendor, 'user' => $user] = appJobsProjectsTeamUser(1);
    Sanctum::actingAs($user, ['app']);

    $client = appJobsProjectsClient($vendor);

    $payload = [
        'client_id' => $client->id,
        'project_name' => 'Basement Stairs',
        'project_existing_address' => 'NEW',
        'address' => '1 Main St',
        'city' => 'Chicago',
    ];

    $first = $this->postJson('/api/app/v1/projects', $payload)->assertCreated();
    $second = $this->postJson('/api/app/v1/projects', $payload)->assertCreated();

    expect($second->json('data.id'))->toBe($first->json('data.id'))
        ->and(Project::withoutGlobalScopes()->where('project_name', 'Basement Stairs')->count())->toBe(1);
});

it('copies the client\'s own address when project_existing_address is CLIENT_PROJECT', function () {
    ['vendor' => $vendor, 'user' => $user] = appJobsProjectsTeamUser(1);
    Sanctum::actingAs($user, ['app']);

    $client = appJobsProjectsClient($vendor, [
        'address' => '789 Client Ave', 'city' => 'Skokie', 'state' => 'IL', 'zip_code' => '60076',
    ]);

    $response = $this->postJson('/api/app/v1/projects', [
        'client_id' => $client->id,
        'project_name' => 'New Deck',
        'project_existing_address' => 'CLIENT_PROJECT',
    ])->assertCreated();

    $project = Project::query()->findOrFail($response->json('data.id'));

    expect($project->address)->toBe('789 Client Ave')
        ->and($project->city)->toBe('Skokie');
});

it('rejects a client from another vendor and missing required fields', function () {
    ['user' => $user] = appJobsProjectsTeamUser(1);
    Sanctum::actingAs($user, ['app']);

    $otherClient = Client::factory()->create();

    $this->postJson('/api/app/v1/projects', [
        'client_id' => $otherClient->id,
        'project_name' => 'Not Mine',
        'project_existing_address' => 'NEW',
        'address' => '1 Main St',
        'city' => 'Chicago',
    ])->assertStatus(422)->assertJsonValidationErrors(['client_id']);

    $this->postJson('/api/app/v1/projects', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['client_id', 'project_name', 'project_existing_address']);
});
