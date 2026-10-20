<?php

use App\Models\Client;
use App\Models\Estimate;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    // No real Meilisearch round trips — same reasoning as AppProjectsTest.
    config(['scout.driver' => null]);
});

/** @return array{vendor: Vendor, user: User} */
function appJobsStatusFilterTeamUser(int $role = 1): array
{
    $vendor = Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1, 'business_name' => 'GS Construction']);
    $user = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $vendor->users()->attach($user->id, ['role_id' => $role]);

    return compact('vendor', 'user');
}

function appJobsStatusFilterProject(Vendor $vendor, string $name = 'Kitchen Remodel'): Project
{
    $client = Client::factory()->create();
    $client->vendors()->attach($vendor->id);

    $project = Project::withoutEvents(fn () => Project::create([
        'project_name' => $name,
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

it('returns every selectable status when asked for the full filter list', function () {
    ['vendor' => $vendor, 'user' => $user] = appJobsStatusFilterTeamUser();
    Sanctum::actingAs($user, ['app']);

    $response = $this->getJson('/api/app/v1/projects/statuses?all=1')->assertOk();

    $codes = collect($response->json('data'))->pluck('code')->all();
    $expected = array_column(ProjectStatus::selectableStatuses(), 'code');

    expect($codes)->toEqual($expected);
});

it('still returns only the dashboard-card statuses by default', function () {
    ['vendor' => $vendor, 'user' => $user] = appJobsStatusFilterTeamUser();
    Sanctum::actingAs($user, ['app']);

    $response = $this->getJson('/api/app/v1/projects/statuses')->assertOk();

    expect($response->json('data'))->toHaveCount(count(\App\Livewire\Projects\ProjectsIndex::STATUS_CARD_CODES));
});

it('filtering by Complete also surfaces a project whose latest status is Service Call', function () {
    ['vendor' => $vendor, 'user' => $user] = appJobsStatusFilterTeamUser();
    Sanctum::actingAs($user, ['app']);

    $project = appJobsStatusFilterProject($vendor);
    ProjectStatus::create([
        'project_id' => $project->id, 'belongs_to_vendor_id' => $vendor->id,
        'status_code' => 7, 'start_date' => '2026-09-01',
    ]);
    ProjectStatus::create([
        'project_id' => $project->id, 'belongs_to_vendor_id' => $vendor->id,
        'status_code' => 8, 'start_date' => '2026-09-05',
    ]);

    $response = $this->getJson('/api/app/v1/projects?status[]=7')->assertOk();

    expect(collect($response->json('data'))->pluck('id')->all())->toContain($project->id);
});

it('accepts multiple status codes at once', function () {
    ['vendor' => $vendor, 'user' => $user] = appJobsStatusFilterTeamUser();
    Sanctum::actingAs($user, ['app']);

    $active = appJobsStatusFilterProject($vendor, 'Active Job');
    ProjectStatus::create([
        'project_id' => $active->id, 'belongs_to_vendor_id' => $vendor->id,
        'status_code' => 6, 'start_date' => '2026-09-01',
    ]);

    $invited = appJobsStatusFilterProject($vendor, 'Invited Job');
    ProjectStatus::create([
        'project_id' => $invited->id, 'belongs_to_vendor_id' => $vendor->id,
        'status_code' => 1, 'start_date' => '2026-09-01',
    ]);

    $response = $this->getJson('/api/app/v1/projects?status[]=6&status[]=1')->assertOk();

    $ids = collect($response->json('data'))->pluck('id')->all();
    expect($ids)->toContain($active->id)->toContain($invited->id);
});

it('needs a token to change a project status', function () {
    $this->postJson('/api/app/v1/projects/1/status', ['code' => 6])->assertUnauthorized();
});

it('changing status to Cancelled soft-deletes the project\'s estimates, same as the web list row', function () {
    ['vendor' => $vendor, 'user' => $user] = appJobsStatusFilterTeamUser();
    Sanctum::actingAs($user, ['app']);

    $project = appJobsStatusFilterProject($vendor);
    $estimate = Estimate::create(['project_id' => $project->id, 'belongs_to_vendor_id' => $vendor->id]);

    $this->postJson("/api/app/v1/projects/{$project->id}/status", ['code' => 10])->assertOk();

    expect($estimate->refresh()->trashed())->toBeTrue()
        ->and(Estimate::onlyTrashed()->whereKey($estimate->id)->exists())->toBeTrue()
        ->and(ProjectStatus::withoutGlobalScopes()->where('project_id', $project->id)->latest('id')->first()->status_code)->toBe(10);
});

it('refuses an unselectable status code', function () {
    ['vendor' => $vendor, 'user' => $user] = appJobsStatusFilterTeamUser();
    Sanctum::actingAs($user, ['app']);

    $project = appJobsStatusFilterProject($vendor);

    $this->postJson("/api/app/v1/projects/{$project->id}/status", ['code' => 999])
        ->assertUnprocessable();
});
