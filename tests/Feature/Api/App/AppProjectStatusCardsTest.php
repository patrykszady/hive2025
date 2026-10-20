<?php

use App\Livewire\Projects\ProjectsIndex;
use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\User;
use App\Models\Vendor;
use App\Services\ProjectStatusTrends;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['scout.driver' => null]);
});

/**
 * GS Construction is vendor id 1 — pinned explicitly, same reasoning as
 * AppProjectsTest's appProjectsTeam().
 *
 * @return array{vendor: Vendor, user: User}
 */
function appJobsStatusCardsTeamUser(): array
{
    $vendor = Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1, 'business_name' => 'GS Construction']);
    $user = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $vendor->users()->attach($user->id, ['role_id' => 1]);

    return compact('vendor', 'user');
}

function appJobsStatusCardsProject(Vendor $vendor, array $statuses): Project
{
    $project = Project::withoutEvents(fn () => Project::create([
        'project_name' => 'Kitchen',
        'client_id' => Client::factory()->create()->id,
        'address' => '806 Parkside',
        'city' => 'Lake Zurich',
        'state' => 'IL',
        'zip_code' => '60047',
        'belongs_to_vendor_id' => $vendor->id,
    ]));

    foreach ($statuses as $startDate => $code) {
        ProjectStatus::withoutEvents(fn () => ProjectStatus::withoutGlobalScopes()->create([
            'project_id' => $project->id,
            'belongs_to_vendor_id' => $vendor->id,
            'status_code' => $code,
            'start_date' => $startDate,
        ]));
    }

    return $project;
}

it('needs a token for the status cards', function () {
    $this->getJson('/api/app/v1/projects/status-cards')->assertUnauthorized();
});

it('returns the same card data as the web\'s ProjectsIndex::statusCards()', function () {
    ['vendor' => $vendor, 'user' => $user] = appJobsStatusCardsTeamUser();
    Sanctum::actingAs($user, ['app']);

    appJobsStatusCardsProject($vendor, ['2026-07-01' => 6]);

    $response = $this->getJson('/api/app/v1/projects/status-cards')->assertOk();

    $expected = ProjectsIndex::buildStatusCards($vendor->id, []);
    expect($response->json('data'))->toBe($expected);

    $active = collect($response->json('data'))->firstWhere('label', 'Active');
    expect($active)->not->toBeNull()
        ->and($active['count'])->toBe(1);
});

it('marks the requested status codes selected', function () {
    ['vendor' => $vendor, 'user' => $user] = appJobsStatusCardsTeamUser();
    Sanctum::actingAs($user, ['app']);

    appJobsStatusCardsProject($vendor, ['2026-07-01' => 6]);

    $response = $this->getJson('/api/app/v1/projects/status-cards?status[]=6')->assertOk();

    $active = collect($response->json('data'))->firstWhere('label', 'Active');
    expect($active['selected'])->toBeTrue();
});

it('mocks ProjectStatusTrends the same way the web test does, proving the builder is shared', function () {
    ['user' => $user] = appJobsStatusCardsTeamUser();
    Sanctum::actingAs($user, ['app']);

    $points = array_fill(0, ProjectStatusTrends::WEEKS + 1, 7);
    $this->mock(ProjectStatusTrends::class)
        ->shouldReceive('forVendor')
        ->andReturn([4 => ['now' => 7, 'before' => 6, 'points' => $points]]);

    $response = $this->getJson('/api/app/v1/projects/status-cards')->assertOk();

    $prep = collect($response->json('data'))->firstWhere('label', 'Prep');
    expect($prep)->toMatchArray(['count' => 7, 'before' => 6, 'delta' => 1, 'better' => true]);
});
