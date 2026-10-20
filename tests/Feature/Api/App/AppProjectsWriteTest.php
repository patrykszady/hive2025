<?php

use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\Task;
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

/**
 * A project belonging to $vendor/$client, Active, with no payments/tasks/
 * expenses — deletable per ProjectPolicy::delete. Created via
 * Project::withoutEvents() (same reasoning as AppBidsTest's
 * appJobsBidsProject()) — ProjectObserver::creating() stamps
 * belongs_to_vendor_id from the CURRENTLY AUTHENTICATED user, which would
 * silently override $vendor here (and crash with no one signed in yet).
 */
function appJobsProjectsProject(Vendor $vendor, Client $client, array $overrides = []): Project
{
    $project = Project::withoutEvents(fn () => Project::create(array_merge([
        'project_name' => 'Test Project',
        'client_id' => $client->id,
        'address' => '123 Main St',
        'city' => 'Northbrook',
        'state' => 'IL',
        'zip_code' => '60062',
        'belongs_to_vendor_id' => $vendor->id,
    ], $overrides)));
    $project->vendors()->attach($vendor->id, ['client_id' => $client->id]);
    ProjectStatus::forceCreate([
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $vendor->id,
        'status_code' => 6,
        'start_date' => now(),
    ]);

    return $project;
}

// --- Update a project (App\Livewire\Projects\ProjectCreate's "Update Project" modal) ---

it('needs a token to update a project', function () {
    $this->postJson('/api/app/v1/projects/1', ['client_id' => 1, 'project_name' => 'X', 'address' => 'Y', 'city' => 'Z'])
        ->assertUnauthorized();
});

it('lets an Admin update a project, repointing the client pivot and re-indexing when the client changes', function () {
    ['vendor' => $vendor, 'user' => $user] = appJobsProjectsTeamUser(1);
    Sanctum::actingAs($user, ['app']);

    $client = appJobsProjectsClient($vendor);
    $newClient = appJobsProjectsClient($vendor);
    $project = appJobsProjectsProject($vendor, $client, ['project_name' => 'Old Name']);

    $response = $this->postJson("/api/app/v1/projects/{$project->id}", [
        'client_id' => $newClient->id,
        'project_name' => 'New Name',
        'address' => '99 Updated St',
        'address_2' => 'Unit 2',
        'city' => 'Evanston',
        'state' => 'IL',
        'zip_code' => '60201',
    ])->assertOk();

    $project->refresh();
    expect($project->project_name)->toBe('New Name')
        ->and($project->client_id)->toBe($newClient->id)
        ->and($project->address)->toBe('99 Updated St')
        ->and($project->city)->toBe('Evanston');

    $pivotClientId = \DB::table('project_vendor')->where('project_id', $project->id)->value('client_id');
    expect($pivotClientId)->toBe($newClient->id);

    $response->assertJsonPath('data.name', 'New Name')
        ->assertJsonPath('data.client.id', $newClient->id);
});

it("doesn't require an address or city when updating a project, unlike create's required_if", function () {
    ['vendor' => $vendor, 'user' => $user] = appJobsProjectsTeamUser(1);
    Sanctum::actingAs($user, ['app']);

    $client = appJobsProjectsClient($vendor);
    $project = appJobsProjectsProject($vendor, $client, ['project_name' => 'Old Name', 'address' => '1 Main St', 'city' => 'Chicago']);

    // ProjectForm::update() validates only client_id/project_name — address/
    // city live on the component, outside the Form's own validate() call —
    // so an Update Project submission with neither still saves.
    $this->postJson("/api/app/v1/projects/{$project->id}", [
        'client_id' => $client->id,
        'project_name' => 'New Name',
    ])->assertOk();

    $project->refresh();
    // Omitting address/city doesn't blank them — they're NOT NULL columns,
    // and "not required" means a partial update leaves them as they were,
    // same as the position/nickname fallback elsewhere in this app.
    expect($project->project_name)->toBe('New Name')
        ->and($project->address)->toBe('1 Main St')
        ->and($project->city)->toBe('Chicago');
});

it('blocks a Member from updating a project, like the web\'s Admin-only ProjectPolicy::update', function () {
    ['vendor' => $vendor, 'user' => $admin] = appJobsProjectsTeamUser(1);
    $client = appJobsProjectsClient($vendor);
    $project = appJobsProjectsProject($vendor, $client, ['project_name' => 'Untouched']);

    $member = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $vendor->users()->attach($member->id, ['role_id' => 2]);
    Sanctum::actingAs($member, ['app']);

    $this->postJson("/api/app/v1/projects/{$project->id}", [
        'client_id' => $client->id,
        'project_name' => 'Hacked Name',
        'address' => '1 Main St',
        'city' => 'Chicago',
    ])->assertForbidden();

    expect($project->refresh()->project_name)->toBe('Untouched');
});

it('rejects a client from another vendor on update', function () {
    ['vendor' => $vendor, 'user' => $user] = appJobsProjectsTeamUser(1);
    Sanctum::actingAs($user, ['app']);

    $client = appJobsProjectsClient($vendor);
    $project = appJobsProjectsProject($vendor, $client);
    $otherClient = Client::factory()->create();

    $this->postJson("/api/app/v1/projects/{$project->id}", [
        'client_id' => $otherClient->id,
        'project_name' => 'Still Three Chars',
        'address' => '1 Main St',
        'city' => 'Chicago',
    ])->assertStatus(422)->assertJsonValidationErrors(['client_id']);
});

it('404s updating a project that does not exist and 403s one belonging to another vendor', function () {
    ['user' => $user] = appJobsProjectsTeamUser(1);
    Sanctum::actingAs($user, ['app']);

    $this->postJson('/api/app/v1/projects/999999', ['client_id' => 1, 'project_name' => 'Valid Name', 'address' => 'Y', 'city' => 'Z'])
        ->assertNotFound();

    $otherVendor = Vendor::factory()->create();
    $otherClient = Client::factory()->create();
    $otherProject = appJobsProjectsProject($otherVendor, $otherClient);

    $this->postJson("/api/app/v1/projects/{$otherProject->id}", ['client_id' => $otherClient->id, 'project_name' => 'Valid Name', 'address' => 'Y', 'city' => 'Z'])
        ->assertForbidden();
});

// --- Delete a project (App\Livewire\Projects\ProjectCreate::delete()) ---

it('needs a token to delete a project', function () {
    $this->deleteJson('/api/app/v1/projects/1')->assertUnauthorized();
});

it('lets an Admin soft-delete a project with nothing blocking it', function () {
    ['vendor' => $vendor, 'user' => $user] = appJobsProjectsTeamUser(1);
    Sanctum::actingAs($user, ['app']);

    $client = appJobsProjectsClient($vendor);
    $project = appJobsProjectsProject($vendor, $client);

    $this->deleteJson("/api/app/v1/projects/{$project->id}")
        ->assertOk()
        ->assertJsonPath('data.deleted', true);

    expect(Project::withoutGlobalScopes()->whereKey($project->id)->first()->trashed())->toBeTrue();
});

it('blocks deleting a project that has a task, like the web\'s ProjectPolicy::delete', function () {
    ['vendor' => $vendor, 'user' => $user] = appJobsProjectsTeamUser(1);
    Sanctum::actingAs($user, ['app']);

    $client = appJobsProjectsClient($vendor);
    $project = appJobsProjectsProject($vendor, $client);
    Task::factory()->create(['project_id' => $project->id, 'belongs_to_vendor_id' => $vendor->id]);

    $this->deleteJson("/api/app/v1/projects/{$project->id}")->assertForbidden();

    expect(Project::withoutGlobalScopes()->whereKey($project->id)->first()->trashed())->toBeFalse();
});

it('blocks a Member from deleting a project', function () {
    ['vendor' => $vendor, 'user' => $admin] = appJobsProjectsTeamUser(1);
    $client = appJobsProjectsClient($vendor);
    $project = appJobsProjectsProject($vendor, $client);

    $member = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $vendor->users()->attach($member->id, ['role_id' => 2]);
    Sanctum::actingAs($member, ['app']);

    $this->deleteJson("/api/app/v1/projects/{$project->id}")->assertForbidden();

    expect(Project::withoutGlobalScopes()->whereKey($project->id)->first()->trashed())->toBeFalse();
});
