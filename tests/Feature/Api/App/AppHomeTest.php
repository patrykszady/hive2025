<?php

use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\Task;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    // No real Meilisearch round trips from fixture setup — same reasoning as
    // AppPlannerTest/AppProjectsTest.
    config(['scout.driver' => null]);

    // A Thursday, so "yesterday .. +6 days" is unambiguous: Wed 2026-10-14
    // .. Thu 2026-10-22. The service reads Carbon::now('America/Chicago')
    // directly (the app sends no browser_date cookie), same reasoning as
    // AppPlannerTest.
    Carbon::setTestNow(Carbon::parse('2026-10-15 12:00:00', 'America/Chicago'));
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * GS Construction is vendor id 1 — pinned explicitly, same reasoning as
 * AppPaymentsTest's appPaymentsTeamUser(). $role is the vendor_user pivot's
 * role_id (1 = Admin, 2 = Member) — UserPolicy::hasAdminRole() and the
 * Hub's monthly chart gate on it.
 *
 * @return array{vendor: Vendor, user: User}
 */
function appHomeTeamUser(int $role = 1): array
{
    $vendor = Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1, 'business_name' => 'GS Construction']);
    $user = User::factory()->create(['primary_vendor_id' => $vendor->id, 'first_name' => 'Patryk', 'last_name' => 'Crew', 'nickname' => null]);
    $vendor->users()->attach($user->id, ['role_id' => $role]);

    return compact('vendor', 'user');
}

/** A project owned by $vendor, for $client — same shape as AppPlannerTest's appPlannerProject(). */
function appHomeProject(Vendor $vendor, Client $client, array $overrides = []): Project
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

function appHomeStatus(Project $project, Vendor $vendor, int $code, ?Carbon $date = null): ProjectStatus
{
    return ProjectStatus::create([
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $vendor->id,
        'status_code' => $code,
        'start_date' => ($date ?? now())->format('Y-m-d'),
    ]);
}

/** A task on $project, assigned to $assignee's user_ids — same shape as AppPlannerTest's appPlannerTask(). */
function appHomeTask(Project $project, User $assignee, array $overrides = []): Task
{
    return Task::withoutEvents(fn () => Task::create(array_merge([
        'project_id' => $project->id,
        'title' => 'Task',
        'type' => 'Task',
        'order' => 0,
        'user_ids' => [$assignee->id],
        'belongs_to_vendor_id' => $project->belongs_to_vendor_id,
        'created_by_user_id' => $assignee->id,
    ], $overrides)));
}

it('needs a token for home', function () {
    $this->getJson('/api/app/v1/home')->assertUnauthorized();
});

it('shows an 8-day window from yesterday through 6 days out, with today flagged', function () {
    ['vendor' => $vendor, 'user' => $user] = appHomeTeamUser();
    Sanctum::actingAs($user, ['app']);

    // A project with no tasks at all — groupedTasks() only pads the 8-day
    // window once the vendor has at least one project to query, matching
    // the web's own VendorTasks::groupedTasks() (an empty project list
    // short-circuits to an empty collection, with no padding, there too).
    appHomeProject($vendor, Client::factory()->create());

    $response = $this->getJson('/api/app/v1/home')->assertOk();

    expect($response->json('data.today'))->toBe('2026-10-15')
        ->and($response->json('data.days'))->toHaveCount(8)
        ->and($response->json('data.days.0.date'))->toBe('2026-10-14')
        ->and($response->json('data.days.0.is_yesterday'))->toBeTrue()
        ->and($response->json('data.days.1.date'))->toBe('2026-10-15')
        ->and($response->json('data.days.1.is_today'))->toBeTrue()
        ->and($response->json('data.days.2.is_tomorrow'))->toBeTrue()
        ->and($response->json('data.days.7.date'))->toBe('2026-10-21');
});

it('groups a task assigned to me by day and project, with the project status', function () {
    ['vendor' => $vendor, 'user' => $user] = appHomeTeamUser();
    Sanctum::actingAs($user, ['app']);

    $client = Client::factory()->create();
    $project = appHomeProject($vendor, $client, ['project_name' => 'Elm Remodel']);
    appHomeStatus($project, $vendor, 6, Carbon::parse('2026-10-10')); // Active

    $task = appHomeTask($project, $user, [
        'title' => 'Tile Install',
        'start_date' => '2026-10-16',
        'end_date' => '2026-10-16',
        'progress' => 40,
        'notes' => 'Watch the dog',
    ]);

    $response = $this->getJson('/api/app/v1/home')->assertOk();
    $days = collect($response->json('data.days'))->keyBy('date');
    $group = $days['2026-10-16']['groups'][0];

    expect($group['project']['id'])->toBe($project->id)
        ->and($group['project']['status']['title'])->toBe('Active')
        ->and($group['tasks'][0]['id'])->toBe($task->id)
        ->and($group['tasks'][0]['progress'])->toBe(40)
        ->and($group['tasks'][0]['notes'])->toBe('Watch the dog')
        ->and($group['tasks'][0]['trashed'])->toBeFalse()
        ->and($response->json('data.task_count'))->toBe(1)
        ->and($response->json('data.distinct_project_count'))->toBe(1);
});

it('never shows a task assigned only to someone else', function () {
    ['vendor' => $vendor, 'user' => $user] = appHomeTeamUser();
    Sanctum::actingAs($user, ['app']);

    $other = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $client = Client::factory()->create();
    $project = appHomeProject($vendor, $client);

    appHomeTask($project, $other, [
        'title' => 'Not Mine',
        'start_date' => '2026-10-16',
        'end_date' => '2026-10-16',
        'user_ids' => [$other->id],
    ]);

    $response = $this->getJson('/api/app/v1/home')->assertOk();

    expect($response->json('data.task_count'))->toBe(0)
        ->and(collect($response->json('data.days'))->flatMap(fn (array $day) => $day['groups']))->toBeEmpty();
});

it('puts an undated task in "unscheduled", grouped by project', function () {
    ['vendor' => $vendor, 'user' => $user] = appHomeTeamUser();
    Sanctum::actingAs($user, ['app']);

    $client = Client::factory()->create();
    $project = appHomeProject($vendor, $client, ['project_name' => 'Elm Remodel']);

    $undated = appHomeTask($project, $user, ['title' => 'Pending Punch List']);

    $response = $this->getJson('/api/app/v1/home')->assertOk();
    $group = collect($response->json('data.unscheduled'))->firstWhere('project.id', $project->id);

    expect($group)->not->toBeNull();
    $row = collect($group['tasks'])->firstWhere('id', $undated->id);
    expect($row['title'])->toBe('Pending Punch List');
});

it('puts a task more than 6 days out in "later", grouped by day and project', function () {
    ['vendor' => $vendor, 'user' => $user] = appHomeTeamUser();
    Sanctum::actingAs($user, ['app']);

    $client = Client::factory()->create();
    $project = appHomeProject($vendor, $client, ['project_name' => 'Elm Remodel']);

    $later = appHomeTask($project, $user, [
        'title' => 'Far Out Task',
        'start_date' => '2026-11-02',
        'end_date' => '2026-11-02',
    ]);

    $response = $this->getJson('/api/app/v1/home')->assertOk();

    $laterDay = collect($response->json('data.later'))->firstWhere('date', '2026-11-02');
    expect($laterDay)->not->toBeNull();
    $row = collect($laterDay['groups'][0]['tasks'])->firstWhere('id', $later->id);
    expect($row['title'])->toBe('Far Out Task');

    // Not double-counted into the regular window or its badge count.
    expect($response->json('data.task_count'))->toBe(0)
        ->and(collect($response->json('data.days'))->flatMap(fn (array $day) => $day['groups']))->toBeEmpty();
});

it('still shows a soft-deleted task, flagged trashed', function () {
    ['vendor' => $vendor, 'user' => $user] = appHomeTeamUser();
    Sanctum::actingAs($user, ['app']);

    $client = Client::factory()->create();
    $project = appHomeProject($vendor, $client);

    $task = appHomeTask($project, $user, [
        'title' => 'Cancelled Task',
        'start_date' => '2026-10-16',
        'end_date' => '2026-10-16',
    ]);
    $task->delete();

    $response = $this->getJson('/api/app/v1/home')->assertOk();
    $days = collect($response->json('data.days'))->keyBy('date');
    $row = collect($days['2026-10-16']['groups'][0]['tasks'])->firstWhere('id', $task->id);

    expect($row['trashed'])->toBeTrue();
});

it('shows the vendor and team snapshot', function () {
    ['vendor' => $vendor, 'user' => $user] = appHomeTeamUser();
    Sanctum::actingAs($user, ['app']);

    $crew = User::factory()->create(['primary_vendor_id' => $vendor->id, 'first_name' => 'Alex']);
    $vendor->users()->attach($crew->id, ['role_id' => 2]);

    $response = $this->getJson('/api/app/v1/home')->assertOk();

    // The seeded test DB already carries other employed members on vendor
    // 1 (see tests/CLAUDE or the project's "test DB has seeded users" note)
    // — assert against the same query AppTeamDirectory runs, not a literal
    // count, so this isn't fragile to that seed.
    $expectedCount = $vendor->users()->employed()->count();

    expect($response->json('data.vendor.id'))->toBe($vendor->id)
        ->and($response->json('data.vendor.business_name'))->toBe($vendor->business_name)
        ->and($response->json('data.team.count'))->toBe($expectedCount)
        ->and(collect($response->json('data.team.members'))->pluck('id'))->toContain($user->id, $crew->id);
});

it('shows the admin-only monthly summary for an Admin, with last year\'s payments one year over', function () {
    ['vendor' => $vendor, 'user' => $admin] = appHomeTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $client = Client::factory()->create();
    $project = appHomeProject($vendor, $client);
    appHomeStatus($project, $vendor, 6, Carbon::parse('2026-10-01'));

    \App\Models\Payment::create([
        'project_id' => $project->id,
        'amount' => 500,
        'date' => '2026-10-05',
        'reference' => 'CHK 1',
        'belongs_to_vendor_id' => $vendor->id,
        'created_by_user_id' => $admin->id,
    ]);
    \App\Models\Payment::create([
        'project_id' => $project->id,
        'amount' => 300,
        'date' => '2025-10-05',
        'reference' => 'CHK 2',
        'belongs_to_vendor_id' => $vendor->id,
        'created_by_user_id' => $admin->id,
    ]);

    $response = $this->getJson('/api/app/v1/home')->assertOk();
    $months = collect($response->json('data.monthly'))->keyBy('month_year');

    expect((float) $months["Oct '26"]['this_year_payments'])->toBe(500.0)
        ->and((float) $months["Oct '26"]['last_year_payments'])->toBe(300.0);
});

it('hides the monthly summary from a Member, like the web', function () {
    ['user' => $member] = appHomeTeamUser(role: 2);
    Sanctum::actingAs($member, ['app']);

    $response = $this->getJson('/api/app/v1/home')->assertOk();

    expect($response->json('data.monthly'))->toBeNull();
});

it('keeps the web Hub\'s own VendorTasks Livewire component in sync with the API', function () {
    ['vendor' => $vendor, 'user' => $user] = appHomeTeamUser();

    $client = Client::factory()->create();
    $project = appHomeProject($vendor, $client);
    appHomeTask($project, $user, [
        'title' => 'Shared Logic Check',
        'start_date' => '2026-10-16',
        'end_date' => '2026-10-16',
    ]);

    $this->actingAs($user);
    Livewire::test(\App\Livewire\Dashboard\VendorTasks::class, ['vendor' => $vendor])
        ->assertSet('taskCount', 1)
        ->assertSet('distinctProjectCount', 1);

    Sanctum::actingAs($user, ['app']);
    $response = $this->getJson('/api/app/v1/home')->assertOk();

    expect($response->json('data.task_count'))->toBe(1)
        ->and($response->json('data.distinct_project_count'))->toBe(1);
});
