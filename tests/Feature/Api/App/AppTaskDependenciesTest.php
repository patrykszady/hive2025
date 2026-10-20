<?php

use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskDependency;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['scout.driver' => null]);
});

/** @return array{vendor: Vendor, user: User} */
function appJobsTaskDepsTeamUser(int $role = 1): array
{
    $vendor = Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1, 'business_name' => 'GS Construction']);
    $user = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $vendor->users()->attach($user->id, ['role_id' => $role]);

    return compact('vendor', 'user');
}

function appJobsTaskDepsProject(Vendor $vendor): Project
{
    $client = Client::factory()->create();
    $client->vendors()->attach($vendor->id);

    $project = Project::withoutEvents(fn () => Project::create([
        'project_name' => 'Dependency Test',
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

function appJobsTaskDepsTask(Project $project, Vendor $vendor, User $user, array $overrides = []): Task
{
    return Task::withoutEvents(fn () => Task::create(array_merge([
        'title' => 'Task',
        'type' => 'Task',
        'order' => 1,
        'project_id' => $project->id,
        'user_ids' => [],
        'belongs_to_vendor_id' => $vendor->id,
        'created_by_user_id' => $user->id,
        'options' => [],
    ], $overrides)));
}

it('needs a token to list available predecessors', function () {
    $this->getJson('/api/app/v1/tasks/1/available-predecessors')->assertUnauthorized();
});

it('lists same-project scheduled tasks as candidates, excluding itself', function () {
    ['vendor' => $vendor, 'user' => $user] = appJobsTaskDepsTeamUser();
    Sanctum::actingAs($user, ['app']);

    $project = appJobsTaskDepsProject($vendor);
    $task = appJobsTaskDepsTask($project, $vendor, $user, ['title' => 'Successor']);
    $candidate = appJobsTaskDepsTask($project, $vendor, $user, [
        'title' => 'Candidate', 'start_date' => now()->addDay()->format('Y-m-d'), 'end_date' => now()->addDay()->format('Y-m-d'),
    ]);
    // Unscheduled — not a candidate.
    appJobsTaskDepsTask($project, $vendor, $user, ['title' => 'No dates yet']);

    $response = $this->getJson("/api/app/v1/tasks/{$task->id}/available-predecessors")->assertOk();

    $titles = collect($response->json('data'))->pluck('title');
    expect($titles->all())->toBe(['Candidate'])
        ->and($titles)->not->toContain('Successor');
    expect($candidate)->not->toBeNull();
});

it('needs a token to list available predecessors for a task that does not exist yet', function () {
    $this->getJson('/api/app/v1/tasks/available-predecessors?project_id=1')->assertUnauthorized();
});

it('lists candidates for a brand-new task, excluding ones already picked this session', function () {
    ['vendor' => $vendor, 'user' => $user] = appJobsTaskDepsTeamUser();
    Sanctum::actingAs($user, ['app']);

    $project = appJobsTaskDepsProject($vendor);
    $candidateA = appJobsTaskDepsTask($project, $vendor, $user, [
        'title' => 'Candidate A', 'start_date' => now()->addDay()->format('Y-m-d'), 'end_date' => now()->addDay()->format('Y-m-d'),
    ]);
    $candidateB = appJobsTaskDepsTask($project, $vendor, $user, [
        'title' => 'Candidate B', 'start_date' => now()->addDays(2)->format('Y-m-d'), 'end_date' => now()->addDays(2)->format('Y-m-d'),
    ]);

    $response = $this->getJson("/api/app/v1/tasks/available-predecessors?project_id={$project->id}")->assertOk();
    expect(collect($response->json('data'))->pluck('id')->all())->toBe([$candidateA->id, $candidateB->id]);

    // Excludes whatever's already chosen this session, same as the web's
    // own $pendingDependencies-filtered list.
    $filtered = $this->getJson("/api/app/v1/tasks/available-predecessors?project_id={$project->id}&exclude[]={$candidateA->id}")->assertOk();
    expect(collect($filtered->json('data'))->pluck('id')->all())->toBe([$candidateB->id]);
});

it('404s available predecessors for a nonexistent project', function () {
    ['user' => $user] = appJobsTaskDepsTeamUser();
    Sanctum::actingAs($user, ['app']);

    $this->getJson('/api/app/v1/tasks/available-predecessors?project_id=999999')->assertNotFound();
});

it('writes predecessors chosen before the task existed, like TaskCreate::save()\'s pendingDependencies flush', function () {
    ['vendor' => $vendor, 'user' => $user] = appJobsTaskDepsTeamUser();
    Sanctum::actingAs($user, ['app']);

    $project = appJobsTaskDepsProject($vendor);
    $predecessor = appJobsTaskDepsTask($project, $vendor, $user, [
        'title' => 'Framing', 'start_date' => now()->format('Y-m-d'), 'end_date' => now()->format('Y-m-d'),
    ]);

    $response = $this->postJson('/api/app/v1/tasks', [
        'title' => 'Drywall',
        'project_id' => $project->id,
        'type' => 'Task',
        'dates' => [now()->addDay()->format('Y-m-d')],
        'pending_dependencies' => [
            ['predecessor_task_id' => $predecessor->id, 'type' => 'finish_to_start', 'lag_days' => 1],
        ],
    ])->assertCreated();

    $task = Task::findOrFail($response->json('data.id'));
    $dependency = TaskDependency::where('successor_task_id', $task->id)->sole();
    expect($dependency->predecessor_task_id)->toBe($predecessor->id)
        ->and($dependency->type)->toBe('finish_to_start')
        ->and($dependency->lag_days)->toBe(1);
});

it('skips a pending dependency that would be circular instead of failing the whole create', function () {
    ['vendor' => $vendor, 'user' => $user] = appJobsTaskDepsTeamUser();
    Sanctum::actingAs($user, ['app']);

    $project = appJobsTaskDepsProject($vendor);
    $existing = appJobsTaskDepsTask($project, $vendor, $user, ['title' => 'Existing']);

    $response = $this->postJson('/api/app/v1/tasks', [
        'title' => 'New Task',
        'project_id' => $project->id,
        'type' => 'Task',
        'dates' => [],
        // A nonexistent predecessor id never resolves (Task::visibleTo()
        // fails), so TaskDependencyWriter::add() throws and this is
        // skipped — the create itself must still succeed.
        'pending_dependencies' => [
            ['predecessor_task_id' => 999999, 'type' => 'finish_to_start'],
        ],
    ])->assertCreated();

    $task = Task::findOrFail($response->json('data.id'));
    expect(TaskDependency::where('successor_task_id', $task->id)->count())->toBe(0);
    expect($existing)->not->toBeNull();
});

it('needs a token to add a dependency', function () {
    $this->postJson('/api/app/v1/tasks/1/dependencies', [])->assertUnauthorized();
});

it('adds a predecessor, like TaskCreate::addDependency()\'s saved branch', function () {
    ['vendor' => $vendor, 'user' => $user] = appJobsTaskDepsTeamUser();
    Sanctum::actingAs($user, ['app']);

    $project = appJobsTaskDepsProject($vendor);
    $predecessor = appJobsTaskDepsTask($project, $vendor, $user, ['title' => 'Predecessor']);
    $task = appJobsTaskDepsTask($project, $vendor, $user, ['title' => 'Successor']);

    $response = $this->postJson("/api/app/v1/tasks/{$task->id}/dependencies", [
        'predecessor_task_id' => $predecessor->id,
        'type' => 'finish_to_start',
        'lag_days' => 2,
    ])->assertCreated();

    expect(TaskDependency::where('predecessor_task_id', $predecessor->id)->where('successor_task_id', $task->id)->exists())->toBeTrue();

    $deps = $response->json('data.predecessor_dependencies');
    expect($deps)->toHaveCount(1)
        ->and($deps[0]['task']['title'])->toBe('Predecessor')
        ->and($deps[0]['lag_days'])->toBe(2);
});

it('refuses another company\'s task as a predecessor', function () {
    ['vendor' => $vendor, 'user' => $user] = appJobsTaskDepsTeamUser();
    Sanctum::actingAs($user, ['app']);

    $project = appJobsTaskDepsProject($vendor);
    $task = appJobsTaskDepsTask($project, $vendor, $user, ['title' => 'Mine']);

    $otherVendor = Vendor::factory()->create();
    $otherClient = Client::factory()->create();
    $otherClient->vendors()->attach($otherVendor->id);
    $otherProject = Project::withoutEvents(fn () => Project::create([
        'project_name' => 'Foreign', 'client_id' => $otherClient->id,
        'address' => '2 Elm St', 'city' => 'Chicago', 'state' => 'IL', 'zip_code' => '60601',
        'belongs_to_vendor_id' => $otherVendor->id,
    ]));
    $otherUser = User::factory()->create(['primary_vendor_id' => $otherVendor->id]);
    $foreignTask = appJobsTaskDepsTask($otherProject, $otherVendor, $otherUser, ['title' => 'Foreign']);

    $this->postJson("/api/app/v1/tasks/{$task->id}/dependencies", [
        'predecessor_task_id' => $foreignTask->id,
        'type' => 'finish_to_start',
    ])->assertStatus(422)->assertJsonValidationErrors(['predecessor_task_id']);

    expect(TaskDependency::where('successor_task_id', $task->id)->count())->toBe(0);
});

it('refuses a circular dependency', function () {
    ['vendor' => $vendor, 'user' => $user] = appJobsTaskDepsTeamUser();
    Sanctum::actingAs($user, ['app']);

    $project = appJobsTaskDepsProject($vendor);
    $a = appJobsTaskDepsTask($project, $vendor, $user, ['title' => 'A']);
    $b = appJobsTaskDepsTask($project, $vendor, $user, ['title' => 'B']);

    TaskDependency::create(['predecessor_task_id' => $a->id, 'successor_task_id' => $b->id, 'type' => 'finish_to_start', 'lag_days' => 0]);

    // B -> A would close the loop.
    $this->postJson("/api/app/v1/tasks/{$a->id}/dependencies", [
        'predecessor_task_id' => $b->id,
        'type' => 'finish_to_start',
    ])->assertStatus(422)->assertJsonValidationErrors(['predecessor_task_id']);
});

it('refuses a duplicate dependency', function () {
    ['vendor' => $vendor, 'user' => $user] = appJobsTaskDepsTeamUser();
    Sanctum::actingAs($user, ['app']);

    $project = appJobsTaskDepsProject($vendor);
    $predecessor = appJobsTaskDepsTask($project, $vendor, $user, ['title' => 'Predecessor']);
    $task = appJobsTaskDepsTask($project, $vendor, $user, ['title' => 'Successor']);
    TaskDependency::create(['predecessor_task_id' => $predecessor->id, 'successor_task_id' => $task->id, 'type' => 'finish_to_start', 'lag_days' => 0]);

    $this->postJson("/api/app/v1/tasks/{$task->id}/dependencies", [
        'predecessor_task_id' => $predecessor->id,
        'type' => 'finish_to_start',
    ])->assertStatus(422)->assertJsonValidationErrors(['predecessor_task_id']);

    expect(TaskDependency::where('successor_task_id', $task->id)->count())->toBe(1);
});

it('needs a token to remove a dependency', function () {
    $this->deleteJson('/api/app/v1/tasks/1/dependencies/1')->assertUnauthorized();
});

it('removes a dependency that belongs to the task', function () {
    ['vendor' => $vendor, 'user' => $user] = appJobsTaskDepsTeamUser();
    Sanctum::actingAs($user, ['app']);

    $project = appJobsTaskDepsProject($vendor);
    $predecessor = appJobsTaskDepsTask($project, $vendor, $user, ['title' => 'Predecessor']);
    $task = appJobsTaskDepsTask($project, $vendor, $user, ['title' => 'Successor']);
    $dependency = TaskDependency::create(['predecessor_task_id' => $predecessor->id, 'successor_task_id' => $task->id, 'type' => 'finish_to_start', 'lag_days' => 0]);

    $this->deleteJson("/api/app/v1/tasks/{$task->id}/dependencies/{$dependency->id}")->assertOk();

    expect(TaskDependency::find($dependency->id))->toBeNull();
});

it('refuses to remove a dependency that does not belong to the open task', function () {
    ['vendor' => $vendor, 'user' => $user] = appJobsTaskDepsTeamUser();
    Sanctum::actingAs($user, ['app']);

    $project = appJobsTaskDepsProject($vendor);
    $task = appJobsTaskDepsTask($project, $vendor, $user, ['title' => 'No deps of its own']);
    $otherA = appJobsTaskDepsTask($project, $vendor, $user, ['title' => 'Unrelated predecessor']);
    $otherB = appJobsTaskDepsTask($project, $vendor, $user, ['title' => 'Unrelated successor']);
    $unrelated = TaskDependency::create(['predecessor_task_id' => $otherA->id, 'successor_task_id' => $otherB->id, 'type' => 'finish_to_start', 'lag_days' => 0]);

    $this->deleteJson("/api/app/v1/tasks/{$task->id}/dependencies/{$unrelated->id}")->assertOk();

    expect(TaskDependency::find($unrelated->id))->not->toBeNull();
});

it('refuses a Member of a different company from modifying a task they can see on a shared project', function () {
    ['vendor' => $gc, 'user' => $gcAdmin] = appJobsTaskDepsTeamUser(1);
    $sub = Vendor::factory()->create();
    $subUser = User::factory()->create(['primary_vendor_id' => $sub->id]);
    $sub->users()->attach($subUser->id, ['role_id' => 1]);

    $project = appJobsTaskDepsProject($gc);
    $project->vendors()->attach($sub->id, ['client_id' => $project->client_id]);
    $task = appJobsTaskDepsTask($project, $gc, $gcAdmin, ['title' => 'GC task on shared project']);
    $predecessor = appJobsTaskDepsTask($project, $gc, $gcAdmin, ['title' => 'Predecessor']);

    Sanctum::actingAs($subUser, ['app']);

    $this->postJson("/api/app/v1/tasks/{$task->id}/dependencies", [
        'predecessor_task_id' => $predecessor->id,
        'type' => 'finish_to_start',
    ])->assertForbidden();

    expect(TaskDependency::where('successor_task_id', $task->id)->count())->toBe(0);
});
