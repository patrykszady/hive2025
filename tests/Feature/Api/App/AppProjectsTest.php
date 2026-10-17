<?php

use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\ProjectTimelapse;
use App\Models\ProjectTimelapseFrame;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Scout\EngineManager;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    // No real Meilisearch round trips from fixture setup (ProjectStatus
    // saves re-index their project) — same reasoning as AppCallsTest.
    config(['scout.driver' => null]);
});

/**
 * GS Construction is vendor id 1 — pinned explicitly, same reasoning as
 * AppAuthTest's appTeamUser().
 *
 * @return array{vendor: Vendor, user: User}
 */
function appProjectsTeam(): array
{
    $vendor = Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1, 'business_name' => 'GS Construction']);
    $user = User::factory()->create(['primary_vendor_id' => $vendor->id]);

    return compact('vendor', 'user');
}

/**
 * A project owned by $vendor, linked to $client through project_vendor
 * (what ProjectScope checks) — and the client linked to the same vendor
 * through client_vendor too (what ClientScope checks, and so what
 * Project::client() finds: it joins through project_vendor but still
 * resolves a `clients` row, which only comes back once ClientScope's own
 * "this client belongs to this vendor" condition is satisfied).
 */
function appProjectFixture(Vendor $vendor, Client $client, array $overrides = []): Project
{
    $project = Project::withoutEvents(fn () => Project::create(array_merge([
        'project_name' => 'Kitchen Remodel',
        'client_id' => $client->id,
        'address' => '123 Main St',
        'city' => 'Northbrook',
        'state' => 'IL',
        'zip_code' => '60062',
        'belongs_to_vendor_id' => $vendor->id,
    ], $overrides)));

    $project->vendors()->attach($vendor->id, ['client_id' => $client->id]);

    if (! $client->vendors()->where('vendors.id', $vendor->id)->exists()) {
        $client->vendors()->attach($vendor->id);
    }

    return $project;
}

function appProjectStatus(Project $project, Vendor $vendor, int $code, ?\Illuminate\Support\Carbon $date = null): ProjectStatus
{
    return ProjectStatus::create([
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $vendor->id,
        'status_code' => $code,
        'start_date' => ($date ?? now())->format('Y-m-d'),
    ]);
}

it('needs a token to list projects', function () {
    $this->getJson('/api/app/v1/projects')->assertUnauthorized();
});

it('returns status chips in the web\'s card order', function () {
    ['user' => $user] = appProjectsTeam();
    Sanctum::actingAs($user, ['app']);

    $this->getJson('/api/app/v1/projects/statuses')
        ->assertOk()
        ->assertExactJson(['data' => [
            ['code' => 6, 'title' => 'Active', 'color' => 'green'],
            ['code' => 8, 'title' => 'Service Call', 'color' => 'orange'],
            ['code' => 4, 'title' => 'Prep', 'color' => 'amber'],
            ['code' => 5, 'title' => 'Scheduled', 'color' => 'lime'],
            ['code' => 2, 'title' => 'Estimate', 'color' => 'blue'],
            ['code' => 3, 'title' => 'Response', 'color' => 'yellow'],
            ['code' => 9, 'title' => 'Consult', 'color' => 'purple'],
            ['code' => 1, 'title' => 'Invited', 'color' => 'zinc'],
        ]]);
});

it('defaults to Active projects only, like the web\'s bare visit', function () {
    ['vendor' => $vendor, 'user' => $user] = appProjectsTeam();
    Sanctum::actingAs($user, ['app']);

    $client = Client::factory()->create();
    $active = appProjectFixture($vendor, $client, ['project_name' => 'Active Job']);
    appProjectStatus($active, $vendor, 6);

    $estimate = appProjectFixture($vendor, $client, ['project_name' => 'Estimate Job']);
    appProjectStatus($estimate, $vendor, 2);

    $response = $this->getJson('/api/app/v1/projects')->assertOk();
    $ids = collect($response->json('data'))->pluck('id');

    expect($ids->all())->toBe([$active->id])
        ->and($response->json('data.0.status.code'))->toBe(6)
        ->and($response->json('data.0.status_since'))->not->toBeNull();
});

it('filters by status[]', function () {
    ['vendor' => $vendor, 'user' => $user] = appProjectsTeam();
    Sanctum::actingAs($user, ['app']);

    $client = Client::factory()->create();
    $active = appProjectFixture($vendor, $client, ['project_name' => 'Active Job']);
    appProjectStatus($active, $vendor, 6);

    $estimate = appProjectFixture($vendor, $client, ['project_name' => 'Estimate Job']);
    appProjectStatus($estimate, $vendor, 2);

    $serviceCall = appProjectFixture($vendor, $client, ['project_name' => 'Service Call Job']);
    appProjectStatus($serviceCall, $vendor, 8);

    $response = $this->getJson('/api/app/v1/projects?status[]=2&status[]=8')->assertOk();
    $ids = collect($response->json('data'))->pluck('id')->sort()->values();

    expect($ids->all())->toBe(collect([$estimate->id, $serviceCall->id])->sort()->values()->all())
        ->and($ids)->not->toContain($active->id);
});

it('searches by project name, address, or client when Meilisearch is unavailable', function () {
    ['vendor' => $vendor, 'user' => $user] = appProjectsTeam();
    Sanctum::actingAs($user, ['app']);

    $client = Client::factory()->create();
    $kitchen = appProjectFixture($vendor, $client, ['project_name' => 'Kitchen Remodel']);
    appProjectStatus($kitchen, $vendor, 6);

    $bath = appProjectFixture($vendor, $client, ['project_name' => 'Bathroom Addition']);
    appProjectStatus($bath, $vendor, 6);

    // Fixtures are done re-indexing (a no-op under the null driver above) —
    // only NOW force AppProjectDirectory's own search() call to fail, so it
    // falls back to the plain LIKE query. Deterministic and makes no real
    // network call either way.
    $this->mock(EngineManager::class, function ($mock) {
        $mock->shouldReceive('engine')->andThrow(new RuntimeException('no search engine in tests'));
    });

    $response = $this->getJson('/api/app/v1/projects?search=Kitchen')->assertOk();
    $ids = collect($response->json('data'))->pluck('id');

    expect($ids->all())->toBe([$kitchen->id]);
});

it('403s another vendor\'s project and 404s a nonexistent one', function () {
    ['user' => $user] = appProjectsTeam();
    Sanctum::actingAs($user, ['app']);

    $otherVendor = Vendor::factory()->create();
    $otherClient = Client::factory()->create();
    $otherProject = appProjectFixture($otherVendor, $otherClient);

    $this->getJson('/api/app/v1/projects/'.$otherProject->id)->assertForbidden();
    $this->getJson('/api/app/v1/projects/999999999')->assertNotFound();
});

it('shows the full detail shape, with photo URLs loadable without a session', function () {
    Storage::fake('files');

    ['vendor' => $vendor, 'user' => $user] = appProjectsTeam();
    Sanctum::actingAs($user, ['app']);

    $client = Client::factory()->create();
    $project = appProjectFixture($vendor, $client, ['note' => 'Side door needs a new lock']);
    appProjectStatus($project, $vendor, 6);

    $album = ProjectTimelapse::create([
        'project_id' => $project->id,
        'title' => 'Project Images',
        'kind' => ProjectTimelapse::KIND_GALLERY,
    ]);

    Storage::disk('files')->put("timelapse/{$project->id}/shot.jpg", 'FAKE-IMAGE-BYTES');

    $frame = ProjectTimelapseFrame::create([
        'project_timelapse_id' => $album->id,
        'filename' => 'shot.jpg',
        'path' => "timelapse/{$project->id}/shot.jpg",
        'disk' => 'files',
        'shot_at' => now(),
        'sort_order' => 1,
    ]);

    $response = $this->getJson('/api/app/v1/projects/'.$project->id)->assertOk();

    $response->assertJsonPath('data.id', $project->id)
        ->assertJsonPath('data.note', 'Side door needs a new lock')
        ->assertJsonPath('data.client.id', $client->id)
        ->assertJsonPath('data.client.people', [])
        ->assertJsonPath('data.areas', [])
        ->assertJsonPath('data.status.code', 6)
        ->assertJsonCount(1, 'data.latest_photos')
        ->assertJsonPath('data.latest_photos.0.id', $frame->id)
        ->assertJsonStructure([
            'data' => [
                'id', 'name', 'address', 'client', 'status', 'status_since', 'last_activity_at',
                'upcoming_task', 'web_path', 'note', 'service_availability', 'areas', 'vendors',
                'upcoming_tasks', 'status_history', 'counts' => ['estimates', 'expenses', 'payments', 'photos'],
                'latest_photos',
            ],
        ]);

    $photoUrl = $response->json('data.latest_photos.0.url');
    expect($photoUrl)->toBeString();

    // The frame route carries no auth/session middleware at all — only
    // `signed` — so a plain GET following the link is exactly what the app
    // (which holds no cookie) will do.
    $this->get($photoUrl)->assertOk();
});

it('changes status by adding a new history row, like StatusCreate does', function () {
    ['vendor' => $vendor, 'user' => $user] = appProjectsTeam();
    Sanctum::actingAs($user, ['app']);

    $client = Client::factory()->create();
    $project = appProjectFixture($vendor, $client);
    appProjectStatus($project, $vendor, 2, now()->subDays(3));

    expect(ProjectStatus::withoutGlobalScopes()->where('project_id', $project->id)->count())->toBe(1);

    $this->postJson("/api/app/v1/projects/{$project->id}/status", ['code' => 6])
        ->assertOk()
        ->assertJsonPath('status.code', 6)
        ->assertJsonPath('status.title', 'Active');

    $history = ProjectStatus::withoutGlobalScopes()->where('project_id', $project->id)->orderBy('id')->get();

    expect($history)->toHaveCount(2)
        ->and($history->first()->status_code)->toBe(2)
        ->and($history->last()->status_code)->toBe(6)
        ->and($history->last()->belongs_to_vendor_id)->toBe($vendor->id);
});

it('rejects an invalid status code', function () {
    ['vendor' => $vendor, 'user' => $user] = appProjectsTeam();
    Sanctum::actingAs($user, ['app']);

    $client = Client::factory()->create();
    $project = appProjectFixture($vendor, $client);

    $this->postJson("/api/app/v1/projects/{$project->id}/status", ['code' => 999])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['code']);
});
