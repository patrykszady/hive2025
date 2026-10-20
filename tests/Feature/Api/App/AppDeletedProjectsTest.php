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
    config(['scout.driver' => null]);
});

/** @return array{vendor: Vendor, user: User} */
function appJobsDeletedProjectsTeamUser(int $role = 1): array
{
    $vendor = Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1, 'business_name' => 'GS Construction']);
    $user = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $vendor->users()->attach($user->id, ['role_id' => $role]);

    return compact('vendor', 'user');
}

/** A soft-deleted project belonging to $vendor, with a client and name so the recovery list can show them. */
function appJobsDeletedProject(Vendor $vendor, array $overrides = []): Project
{
    $client = Client::factory()->create();
    $client->vendors()->attach($vendor->id);

    $project = Project::withoutEvents(fn () => Project::create(array_merge([
        'project_name' => 'Deleted Deck',
        'client_id' => $client->id,
        'address' => '1 Oak St',
        'city' => 'Chicago',
        'state' => 'IL',
        'zip_code' => '60601',
        'belongs_to_vendor_id' => $vendor->id,
    ], $overrides)));
    $project->vendors()->attach($vendor->id, ['client_id' => $client->id]);
    ProjectStatus::forceCreate([
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $vendor->id,
        'status_code' => 6,
        'start_date' => now(),
    ]);
    $project->delete();

    return $project;
}

it('needs a token to list deleted projects', function () {
    $this->getJson('/api/app/v1/projects/deleted')->assertUnauthorized();
});

it('lists this vendor\'s trashed projects, newest-deleted first', function () {
    ['vendor' => $vendor, 'user' => $user] = appJobsDeletedProjectsTeamUser();
    Sanctum::actingAs($user, ['app']);

    $older = appJobsDeletedProject($vendor, ['project_name' => 'Older Deck']);
    $older->forceFill(['deleted_at' => now()->subDay()])->saveQuietly();
    $newer = appJobsDeletedProject($vendor, ['project_name' => 'Newer Deck']);

    $response = $this->getJson('/api/app/v1/projects/deleted')->assertOk();

    expect(collect($response->json('data'))->pluck('id')->all())->toBe([$newer->id, $older->id])
        ->and($response->json('data.0.name'))->toBe('Newer Deck')
        ->and($response->json('data.0.client.name'))->not->toBeNull();
});

it('never shows another vendor\'s deleted projects', function () {
    ['user' => $user] = appJobsDeletedProjectsTeamUser();
    Sanctum::actingAs($user, ['app']);

    $otherVendor = Vendor::factory()->create();
    appJobsDeletedProject($otherVendor, ['project_name' => 'Someone Else\'s Deck']);

    $this->getJson('/api/app/v1/projects/deleted')
        ->assertOk()
        ->assertExactJson(['data' => [], 'next_cursor' => null]);
});

it('needs a token to restore a project', function () {
    $this->postJson('/api/app/v1/projects/1/restore')->assertUnauthorized();
});

it('lets an Admin restore a trashed project, like DeletedProjectsTable::restoreProject()', function () {
    ['vendor' => $vendor, 'user' => $admin] = appJobsDeletedProjectsTeamUser(1);
    Sanctum::actingAs($admin, ['app']);

    $project = appJobsDeletedProject($vendor);
    expect($project->trashed())->toBeTrue();

    $this->postJson("/api/app/v1/projects/{$project->id}/restore")
        ->assertOk()
        ->assertJsonPath('data.restored', true);

    expect($project->fresh()->trashed())->toBeFalse();
});

it('blocks a Member from restoring, same "delete" gate as deleting', function () {
    ['vendor' => $vendor, 'user' => $member] = appJobsDeletedProjectsTeamUser(2);
    Sanctum::actingAs($member, ['app']);

    $project = appJobsDeletedProject($vendor);

    $this->postJson("/api/app/v1/projects/{$project->id}/restore")->assertForbidden();

    expect($project->fresh()->trashed())->toBeTrue();
});

it('404s restoring a project that was never deleted, or does not exist', function () {
    ['vendor' => $vendor, 'user' => $admin] = appJobsDeletedProjectsTeamUser(1);
    Sanctum::actingAs($admin, ['app']);

    $live = appJobsDeletedProject($vendor);
    $live->restore();

    $this->postJson("/api/app/v1/projects/{$live->id}/restore")->assertNotFound();
    $this->postJson('/api/app/v1/projects/999999/restore')->assertNotFound();
});
