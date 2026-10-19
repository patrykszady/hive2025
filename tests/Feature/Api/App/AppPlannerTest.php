<?php

use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectActionItem;
use App\Models\ProjectStatus;
use App\Models\Task;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    // No real Meilisearch round trips from fixture setup — same reasoning as
    // AppProjectsTest/AppCallsTest.
    config(['scout.driver' => null]);

    // A Thursday, so "this week" is unambiguous: Mon 2026-10-12 .. Sun
    // 2026-10-18. The service reads Carbon::now('America/Chicago') directly
    // (the app sends no browser_date cookie), not browser_today(), so the
    // web planner's config(['app.fake_browser_date' => ...]) trick does not
    // apply here — freezing real "now" is what actually pins it.
    Carbon::setTestNow(Carbon::parse('2026-10-15 12:00:00', 'America/Chicago'));
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * GS Construction is vendor id 1 — pinned explicitly, same reasoning as
 * AppProjectsTest's appProjectsTeam().
 *
 * @return array{vendor: Vendor, user: User}
 */
function appPlannerTeam(): array
{
    $vendor = Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1, 'business_name' => 'GS Construction']);
    $user = User::factory()->create(['primary_vendor_id' => $vendor->id, 'first_name' => 'Patryk', 'last_name' => 'Crew', 'nickname' => null]);

    return compact('vendor', 'user');
}

/** A project owned by $vendor, linked to $client — same shape as AppProjectsTest's appProjectFixture(). */
function appPlannerProject(Vendor $vendor, Client $client, array $overrides = []): Project
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

function appPlannerStatus(Project $project, Vendor $vendor, int $code, ?Carbon $date = null): ProjectStatus
{
    return ProjectStatus::create([
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $vendor->id,
        'status_code' => $code,
        'start_date' => ($date ?? now())->format('Y-m-d'),
    ]);
}

/**
 * A task on $project, created outside Task's own observer (which needs an
 * authenticated session to stamp belongs_to_vendor_id/created_by_user_id —
 * this sets both explicitly instead, and skips the reminder/notification
 * jobs TaskObserver::created() would otherwise queue).
 */
function appPlannerTask(Project $project, User $user, array $overrides = []): Task
{
    return Task::withoutEvents(fn () => Task::create(array_merge([
        'project_id' => $project->id,
        'title' => 'Task',
        'type' => 'Task',
        'order' => 0,
        'belongs_to_vendor_id' => $project->belongs_to_vendor_id,
        'created_by_user_id' => $user->id,
    ], $overrides)));
}

function appPlannerActionItem(Project $project, array $overrides = []): ProjectActionItem
{
    return ProjectActionItem::create(array_merge([
        'project_id' => $project->id,
        'title' => 'Call back about tile samples',
        'owner' => null,
        'status' => ProjectActionItem::STATUS_OPEN,
        'mentions' => 1,
        'first_mentioned_at' => now(),
        'last_mentioned_at' => now(),
        'sources' => [[
            'kind' => 'sms',
            'id' => 1,
            'thread_id' => 1,
            'at' => now()->toIso8601String(),
            'excerpt' => 'Can you call me about the tile samples?',
        ]],
        'fingerprint' => sha1(uniqid('appPlannerActionItem', true)),
    ], $overrides));
}

/** Flatten every task across every day's groups, for a quick "does X appear anywhere" check. */
function appPlannerAllDayTasks(array $responseData): \Illuminate\Support\Collection
{
    return collect($responseData['days'])
        ->flatMap(fn (array $day) => collect($day['groups'])->flatMap(fn (array $group) => $group['tasks']));
}

it('needs a token for the planner and for action items', function () {
    $this->getJson('/api/app/v1/planner')->assertUnauthorized();
    $this->getJson('/api/app/v1/action-items')->assertUnauthorized();
    $this->postJson('/api/app/v1/action-items/1/done')->assertUnauthorized();
});

it('defaults to this week, Monday to Sunday, in America/Chicago', function () {
    ['user' => $user] = appPlannerTeam();
    Sanctum::actingAs($user, ['app']);

    $response = $this->getJson('/api/app/v1/planner')->assertOk();

    expect($response->json('data.from'))->toBe('2026-10-12')
        ->and($response->json('data.to'))->toBe('2026-10-18')
        ->and($response->json('data.today'))->toBe('2026-10-15')
        ->and($response->json('data.days'))->toHaveCount(7)
        ->and($response->json('data.days.0.date'))->toBe('2026-10-12')
        ->and($response->json('data.days.0.is_weekend'))->toBeFalse()
        ->and($response->json('data.days.3.is_today'))->toBeTrue()
        ->and($response->json('data.days.6.is_weekend'))->toBeTrue();
});

it('accepts an explicit from/to range', function () {
    ['user' => $user] = appPlannerTeam();
    Sanctum::actingAs($user, ['app']);

    $response = $this->getJson('/api/app/v1/planner?from=2026-11-01&to=2026-11-05')->assertOk();

    expect($response->json('data.from'))->toBe('2026-11-01')
        ->and($response->json('data.to'))->toBe('2026-11-05')
        ->and($response->json('data.today'))->toBe('2026-10-15')
        ->and($response->json('data.days'))->toHaveCount(5);
});

it('422s a range where from is after to, one given without the other, or a span over 31 days', function () {
    ['user' => $user] = appPlannerTeam();
    Sanctum::actingAs($user, ['app']);

    $this->getJson('/api/app/v1/planner?from=2026-11-05&to=2026-11-01')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['to']);

    $this->getJson('/api/app/v1/planner?from=2026-11-01')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['to']);

    $this->getJson('/api/app/v1/planner?from=2026-01-01&to=2026-02-05')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['to']);

    $this->getJson('/api/app/v1/planner?from=not-a-date&to=2026-11-05')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['from']);
});

it('books a multi-date task on every picked day, with a day_counter, carrying progress/notes/vendor', function () {
    ['vendor' => $vendor, 'user' => $user] = appPlannerTeam();
    Sanctum::actingAs($user, ['app']);

    // $task->vendor is a plain belongsTo, but Vendor::class carries its own
    // VendorScope (every Vendor query, this relation included, is narrowed
    // to GS Construction's own connected sub-vendors) — attaching is what
    // makes the sub visible at all, on the web card just as much as here.
    $tileVendor = Vendor::factory()->create(['business_name' => 'Ace Tile Co']);
    $vendor->vendors()->attach($tileVendor->id);
    $client = Client::factory()->create();
    $project = appPlannerProject($vendor, $client, ['project_name' => 'Elm Remodel']);
    appPlannerStatus($project, $vendor, 6, Carbon::parse('2026-10-10')); // Active

    $task = appPlannerTask($project, $user, [
        'title' => 'Tile Install',
        'options' => ['dates' => ['2026-10-13', '2026-10-15']],
        'start_date' => '2026-10-13',
        'end_date' => '2026-10-15',
        'vendor_id' => $tileVendor->id,
        'progress' => 40,
        'notes' => 'Watch the dog',
    ]);

    $response = $this->getJson('/api/app/v1/planner')->assertOk();
    $days = collect($response->json('data.days'))->keyBy('date');

    $day13Task = $days['2026-10-13']['groups'][0]['tasks'][0];
    $day15Task = $days['2026-10-15']['groups'][0]['tasks'][0];

    expect($day13Task['id'])->toBe($task->id)
        ->and($day13Task['day_counter'])->toBe(['current' => 1, 'total' => 2])
        ->and($day13Task['progress'])->toBe(40)
        ->and($day13Task['notes'])->toBe('Watch the dog')
        ->and($day13Task['vendor'])->toBe($tileVendor->name)
        ->and($day13Task['is_meet'])->toBeFalse()
        ->and($day15Task['day_counter'])->toBe(['current' => 2, 'total' => 2])
        ->and($days['2026-10-14']['groups'])->toBe([]);
});

it('books a start/end-span task on every day in between, with no day_counter', function () {
    ['vendor' => $vendor, 'user' => $user] = appPlannerTeam();
    Sanctum::actingAs($user, ['app']);

    $client = Client::factory()->create();
    $project = appPlannerProject($vendor, $client, ['project_name' => 'Elm Remodel']);
    appPlannerStatus($project, $vendor, 6, Carbon::parse('2026-10-10'));

    $task = appPlannerTask($project, $user, [
        'title' => 'Drywall',
        'options' => [],
        'start_date' => '2026-10-12',
        'end_date' => '2026-10-14',
    ]);

    $response = $this->getJson('/api/app/v1/planner')->assertOk();
    $days = collect($response->json('data.days'))->keyBy('date');

    foreach (['2026-10-12', '2026-10-13', '2026-10-14'] as $date) {
        $ids = collect($days[$date]['groups'][0]['tasks'])->pluck('id');
        expect($ids)->toContain($task->id);

        $row = collect($days[$date]['groups'][0]['tasks'])->firstWhere('id', $task->id);
        expect($row['day_counter'])->toBeNull()
            ->and($row['progress'])->toBeNull()
            ->and($row['notes'])->toBeNull();
    }

    expect($days['2026-10-15']['groups'])->toBe([]);
});

it('marks a Meet task is_meet, with its arrival time label', function () {
    ['vendor' => $vendor, 'user' => $user] = appPlannerTeam();
    Sanctum::actingAs($user, ['app']);

    $client = Client::factory()->create();
    $project = appPlannerProject($vendor, $client, ['project_name' => 'Elm Remodel']);
    appPlannerStatus($project, $vendor, 6, Carbon::parse('2026-10-10'));

    $task = appPlannerTask($project, $user, [
        'title' => 'Design Consult',
        'type' => 'Meet',
        'options' => [
            'dates' => ['2026-10-16'],
            'time_settings' => ['2026-10-16' => ['use_time' => true, 'start_time' => '08:00', 'end_time' => '08:30']],
        ],
        'start_date' => '2026-10-16',
        'end_date' => '2026-10-16',
    ]);

    $expectedArrival = $task->getArrivalTimeLabel('2026-10-16');
    expect($expectedArrival)->not->toBeNull();

    $response = $this->getJson('/api/app/v1/planner')->assertOk();
    $days = collect($response->json('data.days'))->keyBy('date');
    $row = $days['2026-10-16']['groups'][0]['tasks'][0];

    expect($row['id'])->toBe($task->id)
        ->and($row['type'])->toBe('Meet')
        ->and($row['is_meet'])->toBeTrue()
        ->and($row['arrival'])->toBe($expectedArrival)
        ->and($row['day_counter'])->toBeNull();
});

it('puts undated tasks in to_schedule instead of any day, grouped by project', function () {
    ['vendor' => $vendor, 'user' => $user] = appPlannerTeam();
    Sanctum::actingAs($user, ['app']);

    $subVendor = Vendor::factory()->create(['business_name' => 'Punch List Pros']);
    $vendor->vendors()->attach($subVendor->id);
    $client = Client::factory()->create();
    $project = appPlannerProject($vendor, $client, ['project_name' => 'Elm Remodel']);
    appPlannerStatus($project, $vendor, 6, Carbon::parse('2026-10-10'));

    $undated = appPlannerTask($project, $user, [
        'title' => 'Pending Punch List',
        'options' => [],
        'vendor_id' => $subVendor->id,
    ]);

    $response = $this->getJson('/api/app/v1/planner')->assertOk();
    $data = $response->json('data');

    expect(appPlannerAllDayTasks($data)->pluck('id'))->not->toContain($undated->id);

    $toSchedule = collect($data['to_schedule']);
    $group = $toSchedule->firstWhere('project.id', $project->id);

    expect($group)->not->toBeNull();
    $row = collect($group['tasks'])->firstWhere('id', $undated->id);
    expect($row)->toBe([
        'id' => $undated->id,
        'title' => 'Pending Punch List',
        'type' => 'Task',
        'vendor' => $subVendor->name,
        'users' => [],
    ]);
});

it('excludes a quiet, non-planner-status project, but includes one with a task touching the range', function () {
    ['vendor' => $vendor, 'user' => $user] = appPlannerTeam();
    Sanctum::actingAs($user, ['app']);

    $client = Client::factory()->create();

    $quiet = appPlannerProject($vendor, $client, ['project_name' => 'Quiet Job']);
    appPlannerStatus($quiet, $vendor, 2, Carbon::parse('2026-10-01')); // Estimate — not a planner status, and no tasks.

    $withTask = appPlannerProject($vendor, $client, ['project_name' => 'Drop-in Job']);
    appPlannerStatus($withTask, $vendor, 2, Carbon::parse('2026-10-01')); // Also Estimate.
    $task = appPlannerTask($withTask, $user, [
        'title' => 'Drop-in',
        'options' => ['dates' => ['2026-10-13']],
        'start_date' => '2026-10-13',
        'end_date' => '2026-10-13',
    ]);

    $response = $this->getJson('/api/app/v1/planner')->assertOk();
    $data = $response->json('data');

    $allProjectIds = collect($data['days'])
        ->flatMap(fn (array $day) => collect($day['groups'])->pluck('project.id'))
        ->merge(collect($data['to_schedule'])->pluck('project.id'));

    expect($allProjectIds)->not->toContain($quiet->id)
        ->and($allProjectIds)->toContain($withTask->id);

    $days = collect($data['days'])->keyBy('date');
    expect(collect($days['2026-10-13']['groups'][0]['tasks'])->pluck('id'))->toContain($task->id);
});

it('resolves task users from the user_ids column', function () {
    ['vendor' => $vendor, 'user' => $user] = appPlannerTeam();
    Sanctum::actingAs($user, ['app']);

    $crew = User::factory()->create(['primary_vendor_id' => $vendor->id, 'first_name' => 'Alex', 'last_name' => 'Helper', 'nickname' => null]);

    $client = Client::factory()->create();
    $project = appPlannerProject($vendor, $client, ['project_name' => 'Elm Remodel']);
    appPlannerStatus($project, $vendor, 6, Carbon::parse('2026-10-10'));

    $task = appPlannerTask($project, $user, [
        'title' => 'Framing',
        'options' => ['dates' => ['2026-10-13']],
        'start_date' => '2026-10-13',
        'end_date' => '2026-10-13',
        'user_ids' => [$crew->id],
    ]);

    $response = $this->getJson('/api/app/v1/planner')->assertOk();
    $days = collect($response->json('data.days'))->keyBy('date');
    $row = collect($days['2026-10-13']['groups'][0]['tasks'])->firstWhere('id', $task->id);

    expect($row['users'])->toBe(['Alex']);
});

it('never shows another vendor\'s project, even an Active one with a task this week', function () {
    ['user' => $user] = appPlannerTeam();
    Sanctum::actingAs($user, ['app']);

    $otherVendor = Vendor::factory()->create();
    $otherClient = Client::factory()->create();
    $otherProject = appPlannerProject($otherVendor, $otherClient, ['project_name' => 'Not Yours']);
    appPlannerStatus($otherProject, $otherVendor, 6, Carbon::parse('2026-10-10'));
    appPlannerTask($otherProject, $user, [
        'title' => 'Secret Task',
        'options' => ['dates' => ['2026-10-13']],
        'start_date' => '2026-10-13',
        'end_date' => '2026-10-13',
    ]);

    $response = $this->getJson('/api/app/v1/planner')->assertOk();
    $data = $response->json('data');

    $allProjectIds = collect($data['days'])
        ->flatMap(fn (array $day) => collect($day['groups'])->pluck('project.id'))
        ->merge(collect($data['to_schedule'])->pluck('project.id'));

    expect($allProjectIds)->not->toContain($otherProject->id)
        ->and(appPlannerAllDayTasks($data)->pluck('title'))->not->toContain('Secret Task');
});

it('groups and ranks action items: the signed-in person, then the crew, then others, then unassigned', function () {
    ['vendor' => $vendor, 'user' => $user] = appPlannerTeam();
    Sanctum::actingAs($user, ['app']);

    $client = Client::factory()->create();
    $project = appPlannerProject($vendor, $client, ['project_name' => 'Action Job']);
    appPlannerStatus($project, $vendor, 6, Carbon::parse('2026-10-10'));

    appPlannerActionItem($project, ['title' => 'Call Patryk back', 'owner' => 'Patryk', 'last_mentioned_at' => now()->subDay()]);
    appPlannerActionItem($project, ['title' => 'Order tile', 'owner' => 'GS', 'last_mentioned_at' => now()->subDays(2)]);
    appPlannerActionItem($project, ['title' => 'Client wants a paint sample', 'owner' => 'Homeowner', 'last_mentioned_at' => now()->subDays(3)]);
    appPlannerActionItem($project, ['title' => 'Unknown ask', 'owner' => null, 'last_mentioned_at' => now()->subDays(20)]);

    $response = $this->getJson('/api/app/v1/action-items')->assertOk();
    $row = collect($response->json('data'))->firstWhere('project.id', $project->id);

    expect($row['project'])->toBe(['id' => $project->id, 'name' => $project->project_name, 'short_address' => $project->short_address])
        ->and($row['recent'])->toBe(3)
        ->and($row['older'])->toBe(1)
        ->and(collect($row['groups'])->pluck('owner')->all())->toBe(['Patryk', 'GS', 'Homeowner', 'Unassigned']);

    $mineItem = collect($row['groups'])->firstWhere('owner', 'Patryk')['items'][0];
    expect($mineItem['title'])->toBe('Call Patryk back')
        ->and($mineItem['mentions'])->toBe(1)
        ->and($mineItem['sources'][0])->toMatchArray([
            // The default fixture source's own 'at' (today, frozen by
            // setTestNow) — independent of the 'last_mentioned_at' override
            // above, same as the real extractor's sources vs. last_mentioned_at.
            'label' => 'text '.now()->format('M j'),
            'excerpt' => 'Can you call me about the tile samples?',
        ]);
});

it('marks an action item done, with done_evidence', function () {
    ['vendor' => $vendor, 'user' => $user] = appPlannerTeam();
    Sanctum::actingAs($user, ['app']);

    $client = Client::factory()->create();
    $project = appPlannerProject($vendor, $client);
    $item = appPlannerActionItem($project, ['title' => 'Fix the gate']);

    $this->postJson("/api/app/v1/action-items/{$item->id}/done")
        ->assertOk()
        ->assertExactJson(['data' => ['id' => $item->id, 'status' => 'done']]);

    $item->refresh();
    expect($item->status)->toBe(ProjectActionItem::STATUS_DONE)
        ->and($item->done_at)->not->toBeNull()
        ->and($item->done_evidence['kind'])->toBe('user')
        ->and($item->done_evidence['id'])->toBe($user->id)
        ->and($item->done_evidence['excerpt'])->toBe('Marked done in the app');
});

it('dismisses an action item, with done_evidence and no done_at', function () {
    ['vendor' => $vendor, 'user' => $user] = appPlannerTeam();
    Sanctum::actingAs($user, ['app']);

    $client = Client::factory()->create();
    $project = appPlannerProject($vendor, $client);
    $item = appPlannerActionItem($project, ['title' => 'Cancel the order']);

    $this->postJson("/api/app/v1/action-items/{$item->id}/dismiss")
        ->assertOk()
        ->assertExactJson(['data' => ['id' => $item->id, 'status' => 'dismissed']]);

    $item->refresh();
    expect($item->status)->toBe(ProjectActionItem::STATUS_DISMISSED)
        ->and($item->done_at)->toBeNull()
        ->and($item->done_evidence['excerpt'])->toBe('Marked dismissed in the app');
});

it('404s done/dismiss for an item on another vendor\'s project, and for a nonexistent one', function () {
    ['user' => $user] = appPlannerTeam();
    Sanctum::actingAs($user, ['app']);

    $otherVendor = Vendor::factory()->create();
    $otherClient = Client::factory()->create();
    $otherProject = appPlannerProject($otherVendor, $otherClient);
    $foreignItem = appPlannerActionItem($otherProject);

    $this->postJson("/api/app/v1/action-items/{$foreignItem->id}/done")->assertNotFound();
    $this->postJson('/api/app/v1/action-items/999999999/done')->assertNotFound();
    $this->postJson("/api/app/v1/action-items/{$foreignItem->id}/dismiss")->assertNotFound();

    expect($foreignItem->fresh()->status)->toBe(ProjectActionItem::STATUS_OPEN);
});
