<?php

use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\SmsGroupThread;
use App\Models\SmsThreadParticipant;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Scout\EngineManager;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    // No real Meilisearch round trips from fixture setup — same reasoning
    // as AppCallsTest / AppProjectsTest.
    config(['scout.driver' => null]);
});

/**
 * GS Construction is vendor id 1 — pinned explicitly, same reasoning as
 * AppAuthTest's appTeamUser().
 *
 * @return array{vendor: Vendor, user: User}
 */
function appClientsTeam(): array
{
    $vendor = Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1, 'business_name' => 'GS Construction']);
    $user = User::factory()->create(['primary_vendor_id' => $vendor->id]);

    return compact('vendor', 'user');
}

/** A client linked to $vendor through client_vendor (what ClientScope checks). */
function appClientFixture(Vendor $vendor, array $overrides = []): Client
{
    $client = Client::factory()->create($overrides);
    $client->vendors()->attach($vendor->id);

    return $client;
}

it('needs a token to list clients', function () {
    $this->getJson('/api/app/v1/clients')->assertUnauthorized();
});

it('lists this vendor\'s clients newest first, with their people and project count', function () {
    ['vendor' => $vendor, 'user' => $user] = appClientsTeam();
    Sanctum::actingAs($user, ['app']);

    $older = appClientFixture($vendor, ['business_name' => 'Johnson Household']);
    $newer = appClientFixture($vendor, ['business_name' => 'Smith Household']);

    $person = User::factory()->create(['first_name' => 'Carrie', 'last_name' => 'Smith']);
    $newer->users()->attach($person->id);

    $newerProject = Project::withoutEvents(fn () => Project::create([
        'project_name' => 'Deck',
        'client_id' => $newer->id,
        'address' => '1 Oak St',
        'city' => 'Northbrook',
        'state' => 'IL',
        'zip_code' => '60062',
        'belongs_to_vendor_id' => $vendor->id,
    ]));
    // ProjectScope hides a project with no project_vendor row, so
    // projects_count (an app.v1 Client::projects() count) would otherwise
    // stay 0 even though the FK is set.
    $newerProject->vendors()->attach($vendor->id, ['client_id' => $newer->id]);

    $otherVendor = Vendor::factory()->create();
    $otherClient = appClientFixture($otherVendor, ['business_name' => 'Not Ours LLC']);

    $response = $this->getJson('/api/app/v1/clients')->assertOk();
    $ids = collect($response->json('data'))->pluck('id');

    // The seeded Pest database already carries other clients for this
    // vendor (see test-db-has-seeded-users) — assert OUR two are both
    // present, newest (highest id) first, rather than the whole page.
    $mine = $ids->filter(fn ($id) => in_array($id, [$newer->id, $older->id], true))->values();

    expect($mine->all())->toBe([$newer->id, $older->id])
        ->and($ids)->not->toContain($otherClient->id);

    $newerRow = collect($response->json('data'))->firstWhere('id', $newer->id);
    expect($newerRow['people'])->toHaveCount(1)
        ->and($newerRow['people'][0]['first_name'])->toBe('Carrie')
        ->and($newerRow['people'][0])->not->toHaveKey('email')
        ->and($newerRow['projects_count'])->toBe(1)
        ->and($newerRow['web_path'])->toBe('/clients/'.$newer->id);
});

it('sorts the clients list by Created asc/desc via sort/direction, like ClientsTable::sort()', function () {
    ['vendor' => $vendor, 'user' => $user] = appClientsTeam();
    Sanctum::actingAs($user, ['app']);

    // Narrow to just these two with a search term (a plain vendor-1 listing
    // also carries the seeded Pest clients — see test-db-has-seeded-users —
    // which would otherwise crowd both backdated fixtures off page one);
    // same reasoning as the Meilisearch-unavailable test above — force the
    // plain LIKE fallback so the search is deterministic under test.
    $older = appClientFixture($vendor, ['business_name' => 'ZzSortProbe Older Co', 'created_at' => now()->subDays(5)]);
    $newer = appClientFixture($vendor, ['business_name' => 'ZzSortProbe Newer Co', 'created_at' => now()->subDay()]);

    $this->mock(EngineManager::class, function ($mock) {
        $mock->shouldReceive('engine')->andThrow(new RuntimeException('no search engine in tests'));
    });

    $desc = $this->getJson('/api/app/v1/clients?'.http_build_query(['search' => 'ZzSortProbe', 'sort' => 'created_at', 'direction' => 'desc']))->assertOk();
    expect(collect($desc->json('data'))->pluck('id')->all())->toBe([$newer->id, $older->id])
        ->and($desc->json('direction'))->toBe('desc');

    $asc = $this->getJson('/api/app/v1/clients?'.http_build_query(['search' => 'ZzSortProbe', 'sort' => 'created_at', 'direction' => 'asc']))->assertOk();
    expect(collect($asc->json('data'))->pluck('id')->all())->toBe([$older->id, $newer->id])
        ->and($asc->json('direction'))->toBe('asc');
});

it('searches by business name, address, phone, or a person\'s name when Meilisearch is unavailable', function () {
    ['vendor' => $vendor, 'user' => $user] = appClientsTeam();
    Sanctum::actingAs($user, ['app']);

    // A made-up surname: Faker's random street names ("Johnson Street") and
    // people's names would otherwise make the other client match by chance.
    $johnson = appClientFixture($vendor, ['business_name' => 'Zqvjohnson Household']);
    $smith = appClientFixture($vendor, ['business_name' => 'Smith Household']);

    // Fixtures are done; only now force the Scout call inside the search
    // path itself to fail, exercising the plain LIKE fallback.
    $this->mock(EngineManager::class, function ($mock) {
        $mock->shouldReceive('engine')->andThrow(new RuntimeException('no search engine in tests'));
    });

    $response = $this->getJson('/api/app/v1/clients?search=Zqvjohnson')->assertOk();
    $ids = collect($response->json('data'))->pluck('id');

    expect($ids->all())->toBe([$johnson->id])
        ->and($ids)->not->toContain($smith->id);
});

it('403s another vendor\'s client and 404s a nonexistent one', function () {
    ['user' => $user] = appClientsTeam();
    Sanctum::actingAs($user, ['app']);

    $otherVendor = Vendor::factory()->create();
    $otherClient = appClientFixture($otherVendor);

    $this->getJson('/api/app/v1/clients/'.$otherClient->id)->assertForbidden();
    $this->getJson('/api/app/v1/clients/999999999')->assertNotFound();
});

it('shows the full detail shape, including each person\'s email and their projects\' status', function () {
    ['vendor' => $vendor, 'user' => $user] = appClientsTeam();
    Sanctum::actingAs($user, ['app']);

    $client = appClientFixture($vendor, ['business_name' => 'Johnson Household']);
    $person = User::factory()->create(['first_name' => 'Carrie', 'last_name' => 'Johnson', 'email' => 'carrie@example.test']);
    $client->users()->attach($person->id);

    $project = Project::withoutEvents(fn () => Project::create([
        'project_name' => 'Deck',
        'client_id' => $client->id,
        'address' => '1 Oak St',
        'city' => 'Northbrook',
        'state' => 'IL',
        'zip_code' => '60062',
        'belongs_to_vendor_id' => $vendor->id,
    ]));
    $project->vendors()->attach($vendor->id, ['client_id' => $client->id]);

    ProjectStatus::create([
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $vendor->id,
        'status_code' => 6,
        'start_date' => now()->format('Y-m-d'),
    ]);

    $response = $this->getJson('/api/app/v1/clients/'.$client->id)->assertOk();

    $response->assertJsonPath('data.id', $client->id)
        ->assertJsonPath('data.people.0.email', 'carrie@example.test')
        ->assertJsonPath('data.notes', [])
        ->assertJsonPath('data.projects.0.id', $project->id)
        ->assertJsonPath('data.projects.0.status.code', 6)
        ->assertJsonPath('data.projects.0.latest_status_date', now()->format('Y-m-d'))
        ->assertJsonPath('data.web_path', '/clients/'.$client->id)
        ->assertJsonStructure([
            'data' => [
                'id', 'name', 'address', 'home_phone', 'people', 'projects_count', 'created_at',
                'web_path', 'projects' => [['id', 'name', 'address', 'status', 'latest_status_date']], 'upcoming_tasks', 'notes',
            ],
        ]);
});

it('sorts the client\'s projects by latest status date desc, like ProjectsTable::projects()', function () {
    ['vendor' => $vendor, 'user' => $user] = appClientsTeam();
    Sanctum::actingAs($user, ['app']);

    $client = appClientFixture($vendor, ['business_name' => 'Johnson Household']);

    $projectFixture = function (string $name) use ($client, $vendor) {
        $project = Project::withoutEvents(fn () => Project::create([
            'project_name' => $name,
            'client_id' => $client->id,
            'address' => '1 Oak St',
            'city' => 'Northbrook',
            'state' => 'IL',
            'zip_code' => '60062',
            'belongs_to_vendor_id' => $vendor->id,
        ]));
        $project->vendors()->attach($vendor->id, ['client_id' => $client->id]);

        return $project;
    };

    // Created oldest-id-first, but statused out of id order — the sort
    // must follow the status date, not creation/id order (what the app
    // used to fall back to before this field existed).
    $oldestId = $projectFixture('Deck');
    $newestStatus = $projectFixture('Kitchen');
    $middleStatus = $projectFixture('Bath');

    ProjectStatus::create(['project_id' => $oldestId->id, 'belongs_to_vendor_id' => $vendor->id, 'status_code' => 6, 'start_date' => '2026-01-01']);
    ProjectStatus::create(['project_id' => $newestStatus->id, 'belongs_to_vendor_id' => $vendor->id, 'status_code' => 6, 'start_date' => '2026-03-01']);
    ProjectStatus::create(['project_id' => $middleStatus->id, 'belongs_to_vendor_id' => $vendor->id, 'status_code' => 6, 'start_date' => '2026-02-01']);

    // A project with no status at all sorts last.
    $noStatus = $projectFixture('Garage');

    $response = $this->getJson('/api/app/v1/clients/'.$client->id)->assertOk();

    expect(collect($response->json('data.projects'))->pluck('id')->all())
        ->toBe([$newestStatus->id, $middleStatus->id, $oldestId->id, $noStatus->id]);
});

it('tenant isolation: a client visible to one vendor is invisible to another', function () {
    ['user' => $user] = appClientsTeam();
    Sanctum::actingAs($user, ['app']);

    $otherVendor = Vendor::factory()->create();
    $otherClient = appClientFixture($otherVendor, ['business_name' => 'Not Ours LLC']);

    $ids = collect($this->getJson('/api/app/v1/clients')->assertOk()->json('data'))->pluck('id');

    expect($ids)->not->toContain($otherClient->id);
});

it('returns the client\'s SMS thread when one exists', function () {
    ['vendor' => $vendor, 'user' => $user] = appClientsTeam();
    Sanctum::actingAs($user, ['app']);

    $client = appClientFixture($vendor, ['business_name' => 'Johnson Household']);

    $thread = SmsGroupThread::query()->create([
        'from_number' => '+12247354200',
        'participants' => ['+12245550001'],
        'vendor_id' => $vendor->id,
        'client_id' => $client->id,
        'last_activity_at' => now(),
    ]);
    SmsThreadParticipant::query()->create([
        'thread_id' => $thread->id,
        'phone_number' => '+12245550001',
        'opted_in_at' => now(),
    ]);

    $this->getJson("/api/app/v1/clients/{$client->id}/thread")
        ->assertOk()
        ->assertJsonPath('data.id', $thread->id)
        ->assertJsonPath('data.client_id', $client->id);
});

it('returns null when the client has no thread', function () {
    ['vendor' => $vendor, 'user' => $user] = appClientsTeam();
    Sanctum::actingAs($user, ['app']);

    $client = appClientFixture($vendor, ['business_name' => 'No Thread Household']);

    $this->getJson("/api/app/v1/clients/{$client->id}/thread")
        ->assertOk()
        ->assertExactJson(['data' => null]);
});
