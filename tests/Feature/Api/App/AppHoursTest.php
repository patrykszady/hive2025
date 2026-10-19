<?php

use App\Models\Client;
use App\Models\Hour;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\Timesheet;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['scout.driver' => null]);
});

/** @return array{vendor: Vendor, user: User} */
function appFieldHoursTeam(array $overrides = []): array
{
    $vendor = Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1, 'business_name' => 'GS Construction', 'business_type' => 'Sub']);
    $user = User::factory()->create(array_merge(['primary_vendor_id' => $vendor->id], $overrides));
    $user->vendors()->attach($vendor->id, ['role_id' => 2, 'is_employed' => 1]);

    return compact('vendor', 'user');
}

function appFieldHoursProject(Vendor $vendor, array $overrides = []): Project
{
    $client = Client::factory()->create();
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

    return $project;
}

function appFieldProjectStatus(Project $project, Vendor $vendor, int $code): ProjectStatus
{
    return ProjectStatus::create([
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $vendor->id,
        'status_code' => $code,
        'start_date' => now()->format('Y-m-d'),
    ]);
}

it('needs a token for the day view', function () {
    $this->getJson('/api/app/v1/hours?date='.now()->format('Y-m-d'))->assertUnauthorized();
});

it('refuses a 1099 vendor\'s member from logging hours', function () {
    ['user' => $user] = appFieldHoursTeam();
    $user->vendor->forceFill(['business_type' => '1099'])->save();
    Sanctum::actingAs($user, ['app']);

    $this->getJson('/api/app/v1/hours?date='.now()->format('Y-m-d'))->assertForbidden();
});

it('offers Active/Service Call projects, task-day projects, and already-logged projects', function () {
    ['vendor' => $vendor, 'user' => $user] = appFieldHoursTeam();
    Sanctum::actingAs($user, ['app']);
    $date = now()->format('Y-m-d');

    $active = appFieldHoursProject($vendor, ['project_name' => 'Active Job']);
    appFieldProjectStatus($active, $vendor, 6);

    $estimate = appFieldHoursProject($vendor, ['project_name' => 'Estimate Job']);
    appFieldProjectStatus($estimate, $vendor, 2);

    $response = $this->getJson('/api/app/v1/hours?date='.$date)->assertOk();
    $ids = collect($response->json('data.projects'))->pluck('id');

    expect($ids)->toContain($active->id)->and($ids)->not->toContain($estimate->id)
        ->and($response->json('data.locked'))->toBeFalse();
});

it('shows an existing hour entry for the selected day', function () {
    ['vendor' => $vendor, 'user' => $user] = appFieldHoursTeam();
    Sanctum::actingAs($user, ['app']);
    $date = now()->format('Y-m-d');

    $project = appFieldHoursProject($vendor);
    appFieldProjectStatus($project, $vendor, 6);

    Hour::create([
        'date' => $date,
        'hours' => 4.5,
        'project_id' => $project->id,
        'user_id' => $user->id,
        'vendor_id' => $vendor->id,
        'created_by_user_id' => $user->id,
    ]);

    $response = $this->getJson('/api/app/v1/hours?date='.$date)->assertOk();
    $row = collect($response->json('data.projects'))->firstWhere('id', $project->id);

    expect($row['hours'])->toBe(4.5);
});

it('saves a new day\'s entries and rejects a project outside the tenant', function () {
    ['vendor' => $vendor, 'user' => $user] = appFieldHoursTeam();
    Sanctum::actingAs($user, ['app']);
    $date = now()->format('Y-m-d');

    $project = appFieldHoursProject($vendor);

    $otherVendor = Vendor::factory()->create();
    $otherClient = Client::factory()->create();
    $foreignProject = Project::withoutEvents(fn () => Project::create([
        'project_name' => 'Foreign Job',
        'client_id' => $otherClient->id,
        'address' => '1 Away St',
        'city' => 'Chicago',
        'state' => 'IL',
        'zip_code' => '60601',
        'belongs_to_vendor_id' => $otherVendor->id,
    ]));
    $foreignProject->vendors()->attach($otherVendor->id, ['client_id' => $otherClient->id]);

    $this->postJson('/api/app/v1/hours', [
        'date' => $date,
        'entries' => [
            ['project_id' => $project->id, 'hours' => 6],
            ['project_id' => $foreignProject->id, 'hours' => 3],
        ],
    ])->assertOk();

    expect(Hour::where('user_id', $user->id)->where('date', $date)->count())->toBe(1);
    expect(Hour::where('project_id', $foreignProject->id)->count())->toBe(0);
    expect((float) Hour::where('project_id', $project->id)->first()->hours)->toBe(6.0);
});

it('rejects an empty day with no existing hours', function () {
    ['user' => $user] = appFieldHoursTeam();
    Sanctum::actingAs($user, ['app']);
    $date = now()->format('Y-m-d');

    $this->postJson('/api/app/v1/hours', [
        'date' => $date,
        'entries' => [['project_id' => 1, 'hours' => null]],
    ])->assertStatus(422)->assertJsonValidationErrors(['entries']);
});

it('rejects more than 16 hours for one project', function () {
    ['vendor' => $vendor, 'user' => $user] = appFieldHoursTeam();
    Sanctum::actingAs($user, ['app']);
    $project = appFieldHoursProject($vendor);

    $this->postJson('/api/app/v1/hours', [
        'date' => now()->format('Y-m-d'),
        'entries' => [['project_id' => $project->id, 'hours' => 17]],
    ])->assertStatus(422)->assertJsonValidationErrors(['entries.0.hours']);
});

it('updates an existing entry, and zeroing it out deletes it', function () {
    ['vendor' => $vendor, 'user' => $user] = appFieldHoursTeam();
    Sanctum::actingAs($user, ['app']);
    $date = now()->format('Y-m-d');
    $project = appFieldHoursProject($vendor);

    $hour = Hour::create([
        'date' => $date, 'hours' => 4, 'project_id' => $project->id,
        'user_id' => $user->id, 'vendor_id' => $vendor->id, 'created_by_user_id' => $user->id,
    ]);

    $this->postJson('/api/app/v1/hours', [
        'date' => $date,
        'entries' => [['project_id' => $project->id, 'hours' => 8]],
    ])->assertOk();

    expect((float) $hour->refresh()->hours)->toBe(8.0);

    $this->postJson('/api/app/v1/hours', [
        'date' => $date,
        'entries' => [['project_id' => $project->id, 'hours' => 0]],
    ])->assertOk();

    expect(Hour::where('id', $hour->id)->exists())->toBeFalse();
});

it('locks a week once it has been confirmed into a timesheet', function () {
    ['vendor' => $vendor, 'user' => $user] = appFieldHoursTeam();
    Sanctum::actingAs($user, ['app']);

    $monday = Carbon::now('America/Chicago')->startOfWeek(Carbon::MONDAY)->subWeek();
    $lockedDate = $monday->copy()->addDays(2)->format('Y-m-d');

    Timesheet::forceCreate([
        'date' => $monday->format('Y-m-d'),
        'user_id' => $user->id,
        'vendor_id' => $vendor->id,
        'hours' => 10,
        'amount' => 500,
        'hourly' => 50,
        'created_by_user_id' => $user->id,
    ]);

    $project = appFieldHoursProject($vendor);

    $response = $this->getJson('/api/app/v1/hours?date='.$lockedDate)->assertOk();
    expect($response->json('data.locked'))->toBeTrue();

    $this->postJson('/api/app/v1/hours', [
        'date' => $lockedDate,
        'entries' => [['project_id' => $project->id, 'hours' => 4]],
    ])->assertStatus(422)->assertJsonValidationErrors(['date']);
});

it('lists other projects not already offered for the day', function () {
    ['vendor' => $vendor, 'user' => $user] = appFieldHoursTeam();
    Sanctum::actingAs($user, ['app']);
    $date = now()->format('Y-m-d');

    $active = appFieldHoursProject($vendor, ['project_name' => 'Active Job']);
    appFieldProjectStatus($active, $vendor, 6);

    $dormant = appFieldHoursProject($vendor, ['project_name' => 'Dormant Job']);

    $response = $this->getJson('/api/app/v1/hours/other-projects?date='.$date)->assertOk();
    $ids = collect($response->json('data'))->pluck('id');

    expect($ids)->toContain($dormant->id)->and($ids)->not->toContain($active->id);
});
