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
function appJobsStatusHistoryTeamUser(int $role = 1): array
{
    $vendor = Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1, 'business_name' => 'GS Construction']);
    $user = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $vendor->users()->attach($user->id, ['role_id' => $role]);

    return compact('vendor', 'user');
}

function appJobsStatusHistoryProject(Vendor $vendor): Project
{
    $client = Client::factory()->create();
    $client->vendors()->attach($vendor->id);

    $project = Project::withoutEvents(fn () => Project::create([
        'project_name' => 'History Test',
        'client_id' => $client->id,
        'address' => '1 Oak St',
        'city' => 'Chicago',
        'state' => 'IL',
        'zip_code' => '60601',
        'belongs_to_vendor_id' => $vendor->id,
    ]));
    $project->vendors()->attach($vendor->id, ['client_id' => $client->id]);

    return $project;
}

it('needs a token to edit a status-history entry', function () {
    $this->postJson('/api/app/v1/projects/1/statuses/1', ['status_code' => 2, 'start_date' => '2026-01-01'])
        ->assertUnauthorized();
});

it('lets the owning vendor edit a past status entry', function () {
    ['vendor' => $vendor, 'user' => $user] = appJobsStatusHistoryTeamUser();
    Sanctum::actingAs($user, ['app']);

    $project = appJobsStatusHistoryProject($vendor);
    $status = ProjectStatus::withoutGlobalScopes()->create([
        'project_id' => $project->id, 'belongs_to_vendor_id' => $vendor->id,
        'status_code' => 2, 'start_date' => '2026-09-01',
    ]);

    $response = $this->postJson("/api/app/v1/projects/{$project->id}/statuses/{$status->id}", [
        'status_code' => 6, 'start_date' => '2026-09-05',
    ])->assertOk();

    expect($response->json('data.code'))->toBe(6)
        ->and($status->fresh()->status_code)->toBe(6)
        ->and($status->fresh()->start_date->format('Y-m-d'))->toBe('2026-09-05');
});

it('refuses editing a status that belongs to another vendor', function () {
    ['user' => $user] = appJobsStatusHistoryTeamUser();
    Sanctum::actingAs($user, ['app']);

    $otherVendor = Vendor::factory()->create();
    $otherClient = Client::factory()->create();
    $otherClient->vendors()->attach($otherVendor->id);
    $foreignProject = Project::withoutEvents(fn () => Project::create([
        'project_name' => 'Foreign', 'client_id' => $otherClient->id,
        'address' => '2 Elm St', 'city' => 'Chicago', 'state' => 'IL', 'zip_code' => '60601',
        'belongs_to_vendor_id' => $otherVendor->id,
    ]));
    $foreignProject->vendors()->attach($otherVendor->id, ['client_id' => $otherClient->id]);
    $foreignStatus = ProjectStatus::withoutGlobalScopes()->create([
        'project_id' => $foreignProject->id, 'belongs_to_vendor_id' => $otherVendor->id,
        'status_code' => 2, 'start_date' => '2026-09-01',
    ]);

    // Not visible to us at all (ProjectScope), so this 403s before even
    // reaching the ownership check.
    $this->postJson("/api/app/v1/projects/{$foreignProject->id}/statuses/{$foreignStatus->id}", [
        'status_code' => 6, 'start_date' => '2026-09-05',
    ])->assertForbidden();

    expect($foreignStatus->fresh()->status_code)->toBe(2);
});

it('validates required fields on edit', function () {
    ['vendor' => $vendor, 'user' => $user] = appJobsStatusHistoryTeamUser();
    Sanctum::actingAs($user, ['app']);

    $project = appJobsStatusHistoryProject($vendor);
    $status = ProjectStatus::withoutGlobalScopes()->create([
        'project_id' => $project->id, 'belongs_to_vendor_id' => $vendor->id,
        'status_code' => 2, 'start_date' => '2026-09-01',
    ]);

    $this->postJson("/api/app/v1/projects/{$project->id}/statuses/{$status->id}", [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status_code', 'start_date']);
});

it('lets the owning vendor delete a past status entry when more than one exists', function () {
    ['vendor' => $vendor, 'user' => $user] = appJobsStatusHistoryTeamUser();
    Sanctum::actingAs($user, ['app']);

    $project = appJobsStatusHistoryProject($vendor);
    ProjectStatus::withoutGlobalScopes()->create([
        'project_id' => $project->id, 'belongs_to_vendor_id' => $vendor->id,
        'status_code' => 2, 'start_date' => '2026-08-01',
    ]);
    $toDelete = ProjectStatus::withoutGlobalScopes()->create([
        'project_id' => $project->id, 'belongs_to_vendor_id' => $vendor->id,
        'status_code' => 6, 'start_date' => '2026-09-01',
    ]);

    $this->deleteJson("/api/app/v1/projects/{$project->id}/statuses/{$toDelete->id}")
        ->assertOk()
        ->assertJsonPath('data.deleted', true);

    expect(ProjectStatus::withoutGlobalScopes()->find($toDelete->id))->toBeNull();
});

it('refuses to delete the project\'s only status, like the web\'s guard', function () {
    ['vendor' => $vendor, 'user' => $user] = appJobsStatusHistoryTeamUser();
    Sanctum::actingAs($user, ['app']);

    $project = appJobsStatusHistoryProject($vendor);
    $onlyStatus = ProjectStatus::withoutGlobalScopes()->create([
        'project_id' => $project->id, 'belongs_to_vendor_id' => $vendor->id,
        'status_code' => 6, 'start_date' => '2026-09-01',
    ]);

    $this->deleteJson("/api/app/v1/projects/{$project->id}/statuses/{$onlyStatus->id}")
        ->assertStatus(422);

    expect(ProjectStatus::withoutGlobalScopes()->find($onlyStatus->id))->not->toBeNull();
});
