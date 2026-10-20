<?php

use App\Jobs\CreateMeetTaskCalendarEvent;
use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectActionItem;
use App\Models\Task;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['scout.driver' => null]);
});

/**
 * GS Construction is vendor id 1 — pinned explicitly, same reasoning as
 * AppPaymentsTest's appPaymentsTeamUser(). $role is the vendor_user pivot's
 * role_id (1 = Admin, 2 = Member) — TaskPolicy::create is open to any
 * member of a vendor, unlike Project/Bid creation.
 *
 * @return array{vendor: Vendor, user: User}
 */
function appJobsTasksTeamUser(int $role = 1): array
{
    $vendor = Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1, 'business_name' => 'GS Construction']);
    $user = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $vendor->users()->attach($user->id, ['role_id' => $role]);

    return compact('vendor', 'user');
}

/** A project owned by $vendor, linked through project_vendor + client_vendor — same shape as AppProjectsTest's appProjectFixture(). */
function appJobsTasksProject(Vendor $vendor, array $overrides = []): Project
{
    $client = Client::factory()->create();
    $client->vendors()->attach($vendor->id);

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

it('needs a token to write a task', function () {
    $this->postJson('/api/app/v1/tasks', [])->assertUnauthorized();
});

it('lets a Member create a task, like TaskPolicy::create (open to any team member, unlike Project/Bid)', function () {
    ['vendor' => $vendor, 'user' => $user] = appJobsTasksTeamUser(2); // Cezary-like: Member
    Sanctum::actingAs($user, ['app']);

    $project = appJobsTasksProject($vendor);

    $response = $this->postJson('/api/app/v1/tasks', [
        'title' => 'Install cabinets',
        'project_id' => $project->id,
        'type' => 'Task',
        'dates' => ['2026-11-02', '2026-11-03'],
        'time_settings' => [
            '2026-11-02' => ['use_time' => true, 'start_time' => '08:00', 'end_time' => '09:00'],
        ],
        'notes' => 'Bring the level',
        'user_ids' => [$user->id],
    ])->assertCreated();

    $task = Task::query()->findOrFail($response->json('data.id'));

    expect($task->title)->toBe('Install cabinets')
        ->and($task->project_id)->toBe($project->id)
        ->and($task->start_date->format('Y-m-d'))->toBe('2026-11-02')
        ->and($task->end_date->format('Y-m-d'))->toBe('2026-11-03')
        ->and($task->belongs_to_vendor_id)->toBe($vendor->id)
        ->and($task->created_by_user_id)->toBe($user->id)
        ->and((array) $task->options->dates)->toBe(['2026-11-02', '2026-11-03']);

    $response->assertJsonPath('data.title', 'Install cabinets')
        ->assertJsonPath('data.dates', ['2026-11-02', '2026-11-03']);
});

it('creates a Meet task with meeting fields and dispatches the calendar event job', function () {
    Queue::fake();

    ['vendor' => $vendor, 'user' => $user] = appJobsTasksTeamUser(1);
    Sanctum::actingAs($user, ['app']);

    $project = appJobsTasksProject($vendor);

    $response = $this->postJson('/api/app/v1/tasks', [
        'title' => 'Design Consult',
        'project_id' => $project->id,
        'type' => 'Meet',
        'dates' => ['2026-11-05'],
        'meeting_location_type' => 'virtual',
        'meeting_participants' => ['client@example.test'],
    ])->assertCreated();

    expect($response->json('data.is_meet'))->toBeTrue()
        ->and($response->json('data.meeting_location_type'))->toBe('virtual');

    Queue::assertPushed(CreateMeetTaskCalendarEvent::class, fn ($job) => $job->taskId === $response->json('data.id'));
});

it('converts the planner action item it was opened from, like actionItemToTask()/TaskCreate::save()', function () {
    ['vendor' => $vendor, 'user' => $user] = appJobsTasksTeamUser(1);
    Sanctum::actingAs($user, ['app']);

    $project = appJobsTasksProject($vendor);

    $item = ProjectActionItem::create([
        'project_id' => $project->id,
        'title' => 'Call back about tile samples',
        'status' => ProjectActionItem::STATUS_OPEN,
        'mentions' => 1,
        'first_mentioned_at' => now(),
        'last_mentioned_at' => now(),
        'sources' => [],
        'fingerprint' => sha1('x'),
    ]);

    $response = $this->postJson('/api/app/v1/tasks', [
        'title' => 'Call back about tile samples',
        'project_id' => $project->id,
        'type' => 'Task',
        'dates' => [],
        'action_item_id' => $item->id,
    ])->assertCreated();

    $item->refresh();
    expect($item->status)->toBe(ProjectActionItem::STATUS_TASK)
        ->and($item->task_id)->toBe($response->json('data.id'));
});

it('rejects a project from outside this tenant and an invalid type', function () {
    ['vendor' => $vendor, 'user' => $user] = appJobsTasksTeamUser(1);
    Sanctum::actingAs($user, ['app']);

    $otherVendor = Vendor::factory()->create();
    $otherProject = appJobsTasksProject($otherVendor);

    $this->postJson('/api/app/v1/tasks', [
        'title' => 'Sneaky',
        'project_id' => $otherProject->id,
        'type' => 'Task',
        'dates' => [],
    ])->assertStatus(422)->assertJsonValidationErrors(['project_id']);

    $project = appJobsTasksProject($vendor);

    $this->postJson('/api/app/v1/tasks', [
        'title' => 'Bad type',
        'project_id' => $project->id,
        'type' => 'NotAType',
        'dates' => [],
    ])->assertStatus(422)->assertJsonValidationErrors(['type']);
});

it('lets the owning company update and delete a task, and 403s another company', function () {
    ['vendor' => $vendor, 'user' => $user] = appJobsTasksTeamUser(1);
    Sanctum::actingAs($user, ['app']);

    $project = appJobsTasksProject($vendor);
    $task = Task::withoutEvents(fn () => Task::create([
        'project_id' => $project->id,
        'title' => 'Original title',
        'type' => 'Task',
        'order' => 0,
        'belongs_to_vendor_id' => $vendor->id,
        'created_by_user_id' => $user->id,
    ]));

    // The task screen's Project field shows the project's name.
    $this->getJson("/api/app/v1/tasks/{$task->id}")
        ->assertOk()
        ->assertJsonPath('data.project.id', $project->id)
        ->assertJsonPath('data.project.name', $project->project_name);

    $this->postJson("/api/app/v1/tasks/{$task->id}", [
        'title' => 'Updated title',
        'project_id' => $project->id,
        'type' => 'Task',
        'dates' => ['2026-11-10'],
    ])->assertOk()->assertJsonPath('data.title', 'Updated title')->assertJsonPath('data.project.name', $project->project_name);

    expect($task->fresh()->title)->toBe('Updated title');

    $this->deleteJson("/api/app/v1/tasks/{$task->id}")->assertOk();
    expect(Task::query()->whereKey($task->id)->exists())->toBeFalse()
        ->and($task->fresh()->trashed())->toBeTrue();

    // Another company entirely — not the owner, not assigned, not the
    // creator's employer — gets 403 on a fresh (non-deleted) task.
    $otherVendor = Vendor::factory()->create();
    $otherUser = User::factory()->create(['primary_vendor_id' => $otherVendor->id]);
    $otherVendor->users()->attach($otherUser->id, ['role_id' => 1]);

    $secondTask = Task::withoutEvents(fn () => Task::create([
        'project_id' => $project->id,
        'title' => 'Still mine',
        'type' => 'Task',
        'order' => 0,
        'belongs_to_vendor_id' => $vendor->id,
        'created_by_user_id' => $user->id,
    ]));

    Sanctum::actingAs($otherUser, ['app']);

    $this->postJson("/api/app/v1/tasks/{$secondTask->id}", [
        'title' => 'Hijacked',
        'project_id' => $project->id,
        'type' => 'Task',
        'dates' => [],
    ])->assertForbidden();

    $this->deleteJson("/api/app/v1/tasks/{$secondTask->id}")->assertForbidden();
});

// --- Restore (App\Livewire\Tasks\TaskCreate::restoreTask()) ---

it('needs a token to restore a task', function () {
    $this->postJson('/api/app/v1/tasks/1/restore')->assertUnauthorized();
});

it('restores a deleted task, like TaskCreate::restoreTask()', function () {
    ['vendor' => $vendor, 'user' => $user] = appJobsTasksTeamUser(1);
    Sanctum::actingAs($user, ['app']);

    $project = appJobsTasksProject($vendor);
    $task = Task::withoutEvents(fn () => Task::create([
        'project_id' => $project->id,
        'title' => 'Undo me',
        'type' => 'Task',
        'order' => 0,
        'belongs_to_vendor_id' => $vendor->id,
        'created_by_user_id' => $user->id,
    ]));

    $this->deleteJson("/api/app/v1/tasks/{$task->id}")->assertOk();
    expect($task->fresh()->trashed())->toBeTrue();

    $response = $this->postJson("/api/app/v1/tasks/{$task->id}/restore")->assertOk();

    expect($task->fresh()->trashed())->toBeFalse()
        ->and($response->json('data.trashed'))->toBeFalse()
        ->and($response->json('data.title'))->toBe('Undo me');
});

it('404s restoring a task that was never deleted', function () {
    ['vendor' => $vendor, 'user' => $user] = appJobsTasksTeamUser(1);
    Sanctum::actingAs($user, ['app']);

    $project = appJobsTasksProject($vendor);
    $task = Task::withoutEvents(fn () => Task::create([
        'project_id' => $project->id,
        'title' => 'Never deleted',
        'type' => 'Task',
        'order' => 0,
        'belongs_to_vendor_id' => $vendor->id,
        'created_by_user_id' => $user->id,
    ]));

    $this->postJson("/api/app/v1/tasks/{$task->id}/restore")->assertNotFound();
});

it('403s another company trying to restore a task they cannot update', function () {
    ['vendor' => $vendor, 'user' => $user] = appJobsTasksTeamUser(1);

    $project = appJobsTasksProject($vendor);
    $task = Task::withoutEvents(fn () => Task::create([
        'project_id' => $project->id,
        'title' => 'Not yours',
        'type' => 'Task',
        'order' => 0,
        'belongs_to_vendor_id' => $vendor->id,
        'created_by_user_id' => $user->id,
    ]));
    $task->delete();

    $otherVendor = Vendor::factory()->create();
    $otherUser = User::factory()->create(['primary_vendor_id' => $otherVendor->id]);
    $otherVendor->users()->attach($otherUser->id, ['role_id' => 1]);

    Sanctum::actingAs($otherUser, ['app']);

    $this->postJson("/api/app/v1/tasks/{$task->id}/restore")->assertForbidden();
    expect($task->fresh()->trashed())->toBeTrue();
});

it('permanently deletes a task that is reopened and deleted again, like removeTask()\'s force-delete branch', function () {
    ['vendor' => $vendor, 'user' => $user] = appJobsTasksTeamUser(1);
    Sanctum::actingAs($user, ['app']);

    $project = appJobsTasksProject($vendor);
    $task = Task::withoutEvents(fn () => Task::create([
        'project_id' => $project->id,
        'title' => 'Doomed',
        'type' => 'Task',
        'order' => 0,
        'belongs_to_vendor_id' => $vendor->id,
        'created_by_user_id' => $user->id,
    ]));

    $this->deleteJson("/api/app/v1/tasks/{$task->id}")->assertOk();
    expect($task->fresh()->trashed())->toBeTrue();

    $this->deleteJson("/api/app/v1/tasks/{$task->id}")->assertOk();

    expect(Task::withTrashed()->whereKey($task->id)->exists())->toBeFalse();
});

it('404s a nonexistent task and 403s a task on an invisible project', function () {
    ['user' => $user] = appJobsTasksTeamUser(1);
    Sanctum::actingAs($user, ['app']);

    $this->getJson('/api/app/v1/tasks/999999999')->assertNotFound();

    $otherVendor = Vendor::factory()->create();
    $otherProject = appJobsTasksProject($otherVendor);
    $otherTask = Task::withoutEvents(fn () => Task::create([
        'project_id' => $otherProject->id,
        'title' => 'Not mine',
        'type' => 'Task',
        'order' => 0,
        'belongs_to_vendor_id' => $otherVendor->id,
        'created_by_user_id' => $user->id,
    ]));

    $this->getJson("/api/app/v1/tasks/{$otherTask->id}")->assertForbidden();
});

// --- Checklist (App\Livewire\Tasks\TaskCreate's Notes tab checklist, mirrored via App\Services\Tasks\TaskWriter) ---

it('creates a task with a checklist, with no merge needed for a brand-new row', function () {
    ['vendor' => $vendor, 'user' => $user] = appJobsTasksTeamUser(1);
    Sanctum::actingAs($user, ['app']);

    $project = appJobsTasksProject($vendor);

    $response = $this->postJson('/api/app/v1/tasks', [
        'title' => 'Install cabinets',
        'project_id' => $project->id,
        'type' => 'Task',
        'dates' => [],
        'checklist' => [
            ['text' => 'Measure twice'],
            ['text' => 'Order hardware', 'completed' => true],
        ],
    ])->assertCreated();

    $task = Task::query()->findOrFail($response->json('data.id'));
    // Raw storage: TaskWriter::store() writes a brand-new checklist exactly
    // as given (nothing to merge against — same as TaskForm::store()), so an
    // item posted without `completed` (as a hand-built test payload might,
    // unlike the real app screen's always-shaped items) stays absent here;
    // the uid/completed defaults only apply on normalized read — see the
    // response assertions below, which are what an app screen actually sees.
    $stored = json_decode(json_encode($task->options->checklist), true);

    expect($stored)->toHaveCount(2)
        ->and($stored[0]['text'])->toBe('Measure twice')
        ->and($stored[1]['text'])->toBe('Order hardware')
        ->and($stored[1]['completed'])->toBeTrue();

    $response->assertJsonCount(2, 'data.checklist')
        ->assertJsonPath('data.checklist.0.text', 'Measure twice')
        ->assertJsonPath('data.checklist.0.completed', false)
        ->assertJsonPath('data.checklist.1.completed', true);

    expect($response->json('data.checklist.0.uid'))->not->toBe('');
});

it('reads a normalized checklist back, with a uid even for a legacy item saved without one', function () {
    ['vendor' => $vendor, 'user' => $user] = appJobsTasksTeamUser(1);
    Sanctum::actingAs($user, ['app']);

    $project = appJobsTasksProject($vendor);
    $task = Task::withoutEvents(fn () => Task::create([
        'project_id' => $project->id,
        'title' => 'Legacy task',
        'type' => 'Task',
        'order' => 0,
        'belongs_to_vendor_id' => $vendor->id,
        'created_by_user_id' => $user->id,
        'options' => ['checklist' => [['text' => 'Pre-uid item', 'completed' => false]]],
    ]));

    $response = $this->getJson("/api/app/v1/tasks/{$task->id}")->assertOk();

    expect($response->json('data.checklist.0.text'))->toBe('Pre-uid item')
        ->and($response->json('data.checklist.0.uid'))->not->toBe('');
});

it('updates a checklist by adding, completing and removing items', function () {
    ['vendor' => $vendor, 'user' => $user] = appJobsTasksTeamUser(1);
    Sanctum::actingAs($user, ['app']);

    $project = appJobsTasksProject($vendor);
    $task = Task::withoutEvents(fn () => Task::create([
        'project_id' => $project->id,
        'title' => 'Install cabinets',
        'type' => 'Task',
        'order' => 0,
        'belongs_to_vendor_id' => $vendor->id,
        'created_by_user_id' => $user->id,
        'options' => ['checklist' => [
            ['uid' => 'c1', 'text' => 'Measure twice', 'completed' => false],
            ['uid' => 'c2', 'text' => 'Order hardware', 'completed' => false],
        ]],
    ]));

    // The screen loaded with both items unchecked (checklist_original), ticks
    // the first, drops the second, and adds a third.
    $original = [
        ['uid' => 'c1', 'text' => 'Measure twice', 'completed' => false],
        ['uid' => 'c2', 'text' => 'Order hardware', 'completed' => false],
    ];
    $incoming = [
        ['uid' => 'c1', 'text' => 'Measure twice', 'completed' => true],
        ['uid' => 'c3', 'text' => 'Sweep up', 'completed' => false],
    ];

    $this->postJson("/api/app/v1/tasks/{$task->id}", [
        'title' => $task->title,
        'project_id' => $project->id,
        'type' => 'Task',
        'dates' => [],
        'checklist' => $incoming,
        'checklist_original' => $original,
    ])->assertOk();

    $stored = json_decode(json_encode($task->fresh()->options->checklist), true);
    $byUid = collect($stored)->keyBy('uid');

    expect($stored)->toHaveCount(2)
        ->and($byUid->has('c2'))->toBeFalse()
        ->and($byUid['c1']['completed'])->toBeTrue()
        ->and($byUid['c3']['text'])->toBe('Sweep up');
});

it("keeps a box ticked elsewhere while this screen was open, instead of reverting it (Task::mergeChecklist())", function () {
    ['vendor' => $vendor, 'user' => $user] = appJobsTasksTeamUser(1);
    Sanctum::actingAs($user, ['app']);

    $project = appJobsTasksProject($vendor);
    $task = Task::withoutEvents(fn () => Task::create([
        'project_id' => $project->id,
        'title' => 'Install cabinets',
        'type' => 'Task',
        'order' => 0,
        'belongs_to_vendor_id' => $vendor->id,
        'created_by_user_id' => $user->id,
        'options' => ['checklist' => [['uid' => 'c1', 'text' => 'Measure twice', 'completed' => false]]],
    ]));

    // This screen loaded with the box unchecked...
    $original = [['uid' => 'c1', 'text' => 'Measure twice', 'completed' => false]];

    // ...then, before this screen saves, a task card ticks it in the DB...
    $task->update(['options' => ['checklist' => [['uid' => 'c1', 'text' => 'Measure twice', 'completed' => true]]]]);

    // ...and this screen now saves, never having touched that box itself
    // (incoming === original for that item).
    $this->postJson("/api/app/v1/tasks/{$task->id}", [
        'title' => $task->title,
        'project_id' => $project->id,
        'type' => 'Task',
        'dates' => [],
        'checklist' => $original,
        'checklist_original' => $original,
    ])->assertOk()->assertJsonPath('data.checklist.0.completed', true);

    $stored = json_decode(json_encode($task->fresh()->options->checklist), true);
    expect($stored[0]['completed'])->toBeTrue();
});
