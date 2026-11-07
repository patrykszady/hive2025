<?php

use App\Livewire\Notifications\NotificationIndex;
use App\Models\AppNotification;
use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\Task;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * The "Carrie & John Krupa shared preferred times" bug (Patryk, 2026-11-07):
 * service_availability_submitted notifications pointed at the bare
 * '/planner', which is not a route — clicking one 404'd. Rows created from
 * now on carry a real planner.cards?task= link (see ServiceAvailabilityTest),
 * but the ones already sitting in app_notifications still say '/planner';
 * these pin that every old row like that still goes somewhere real, and that
 * '/planner' itself is no longer a dead end for any other stray link to it.
 */
function notifLinkVendorAdmin(): array
{
    $vendor = Vendor::factory()->create(['business_type' => 'GC']);

    $admin = User::query()->create([
        'first_name' => 'Notif',
        'last_name' => 'Admin',
        'email' => 'notif-admin-'.uniqid().'@example.test',
        'cell_phone' => '224'.rand(1000000, 9999999),
        'primary_vendor_id' => $vendor->id,
    ]);
    $admin->vendors()->attach($vendor->id, ['role_id' => 1]);

    return [$vendor, $admin];
}

function notifLinkProject(Vendor $vendor): Project
{
    return Project::factory()->create([
        'belongs_to_vendor_id' => $vendor->id,
        'client_id' => Client::factory()->create()->id,
    ]);
}

function notifLinkTask(Project $project, Vendor $vendor, array $overrides = []): Task
{
    return Task::withoutEvents(fn () => Task::create(array_merge([
        'title' => 'Service task',
        'type' => 'task',
        'order' => 1,
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $vendor->id,
        'created_by_user_id' => 1,
        'created_at' => now(),
    ], $overrides)));
}

function oldPlannerNotification(User $admin, Project $project): AppNotification
{
    return AppNotification::create([
        'user_id' => $admin->id,
        'type' => 'service_availability_submitted',
        'title' => 'A homeowner shared preferred times',
        'body' => 'Preferred service times submitted. Schedule the service call.',
        'action_url' => '/planner',
        'data' => ['project_id' => $project->id],
    ]);
}

it('resolves an old /planner notification to the project\'s pending task, not a 404', function (): void {
    [$vendor, $admin] = notifLinkVendorAdmin();
    $this->actingAs($admin);
    $project = notifLinkProject($vendor);

    $earlierTask = notifLinkTask($project, $vendor, ['title' => 'Earlier task']);
    notifLinkTask($project, $vendor, ['title' => 'Later task']);

    $notification = oldPlannerNotification($admin, $project);

    Livewire::test(NotificationIndex::class)
        ->call('goToNotification', $notification->id)
        ->assertRedirect(route('planner.cards', ['task' => $earlierTask->id]));

    expect($notification->fresh()->isRead())->toBeTrue();
});

it('falls back to the project when nothing on it is pending any more', function (): void {
    [$vendor, $admin] = notifLinkVendorAdmin();
    $this->actingAs($admin);
    $project = notifLinkProject($vendor);

    // Already scheduled — nothing pending to deep-link a task to.
    notifLinkTask($project, $vendor, ['start_date' => now()->toDateString()]);

    $notification = oldPlannerNotification($admin, $project);

    Livewire::test(NotificationIndex::class)
        ->call('goToNotification', $notification->id)
        ->assertRedirect(route('projects.show', $project));
});

it('leaves an already-resolved notification action_url untouched', function (): void {
    [$vendor, $admin] = notifLinkVendorAdmin();
    $this->actingAs($admin);
    $project = notifLinkProject($vendor);
    $task = notifLinkTask($project, $vendor);

    $notification = AppNotification::create([
        'user_id' => $admin->id,
        'type' => 'service_availability_submitted',
        'title' => 'A homeowner shared preferred times',
        'body' => 'Preferred service times submitted. Schedule the service call.',
        'action_url' => route('planner.cards', ['task' => $task->id]),
        'data' => ['project_id' => $project->id, 'task_id' => $task->id],
    ]);

    Livewire::test(NotificationIndex::class)
        ->call('goToNotification', $notification->id)
        ->assertRedirect(route('planner.cards', ['task' => $task->id]));
});

it('resolves a collapsed group\'s old /planner notification the same way', function (): void {
    [$vendor, $admin] = notifLinkVendorAdmin();
    $this->actingAs($admin);
    $project = notifLinkProject($vendor);
    $task = notifLinkTask($project, $vendor);

    $notification = oldPlannerNotification($admin, $project);

    Livewire::test(NotificationIndex::class)
        ->call('goToGroup', [$notification->id])
        ->assertRedirect(route('planner.cards', ['task' => $task->id]));
});

it('falls back to the same client\'s project when the notified project has no pending task, even with an already-resolved action_url', function (): void {
    [$vendor, $admin] = notifLinkVendorAdmin();
    $this->actingAs($admin);

    // The Krupa shape: the notified project is zero-task and long closed,
    // its action_url already resolved (at creation time) to that project
    // rather than the legacy '/planner' — the SAME-client fallback must
    // still kick in, not just the legacy-string check.
    $staleProject = notifLinkProject($vendor);

    $activeProject = Project::factory()->create([
        'belongs_to_vendor_id' => $vendor->id,
        'client_id' => $staleProject->client_id,
    ]);
    ProjectStatus::withoutEvents(fn () => ProjectStatus::create([
        'project_id' => $activeProject->id,
        'belongs_to_vendor_id' => $vendor->id,
        'status_code' => 8, // Service Call
        'start_date' => now(),
    ]));
    $pendingTask = notifLinkTask($activeProject, $vendor);

    $notification = AppNotification::create([
        'user_id' => $admin->id,
        'type' => 'service_availability_submitted',
        'title' => 'A homeowner shared preferred times',
        'body' => 'Preferred service times submitted. Schedule the service call.',
        'action_url' => route('projects.show', $staleProject),
        'data' => ['project_id' => $staleProject->id, 'task_id' => null],
    ]);

    Livewire::test(NotificationIndex::class)
        ->call('goToNotification', $notification->id)
        ->assertRedirect(route('planner.cards', ['task' => $pendingTask->id]));
});

it('redirects /planner permanently to /planner/cards, so no other old link 404s', function (): void {
    $this->withoutMiddleware();

    $this->get('/planner')
        ->assertRedirect('/planner/cards')
        ->assertStatus(301);
});
