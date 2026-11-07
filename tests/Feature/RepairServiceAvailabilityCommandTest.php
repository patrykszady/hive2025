<?php

use App\Models\AppNotification;
use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\Task;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * service-availability:repair — the Krupa bug's repair side (Patryk,
 * 2026-11-07): a project whose service_availability has no pending task of
 * its own (a finished job with zero tasks) gets its submission moved to the
 * same client's project that actually has pending work, and the matching
 * Hub notification re-pointed to it, the same target
 * Task::bestServiceAvailabilityProject() picks for a fresh submission.
 *
 * @return array{source: Project, target: Project, task: Task, notification: AppNotification, admin: User}
 */
function repairCommandClientProjects(): array
{
    $client = Client::factory()->create();
    $vendor = Vendor::factory()->create(['business_type' => 'GC']);

    $source = Project::withoutEvents(fn () => Project::create([
        'project_name' => 'Finished Job',
        'client_id' => $client->id,
        'belongs_to_vendor_id' => $vendor->id,
        'address' => '901 Isabella St',
        'city' => 'Cary',
        'state' => 'IL',
        'zip_code' => '60013',
    ]));
    ProjectStatus::withoutEvents(fn () => ProjectStatus::create([
        'project_id' => $source->id,
        'belongs_to_vendor_id' => $vendor->id,
        'status_code' => 7, // Complete
        'start_date' => now()->subYears(2),
    ]));

    $target = Project::withoutEvents(fn () => Project::create([
        'project_name' => 'Open Job',
        'client_id' => $client->id,
        'belongs_to_vendor_id' => $vendor->id,
        'address' => '901 Isabella St',
        'city' => 'Cary',
        'state' => 'IL',
        'zip_code' => '60013',
    ]));
    ProjectStatus::withoutEvents(fn () => ProjectStatus::create([
        'project_id' => $target->id,
        'belongs_to_vendor_id' => $vendor->id,
        'status_code' => 8, // Service Call
        'start_date' => now(),
    ]));

    $task = Task::withoutEvents(fn () => Task::create([
        'title' => 'Pending Work',
        'type' => 'task',
        'order' => 1,
        'project_id' => $target->id,
        'belongs_to_vendor_id' => $vendor->id,
        'created_by_user_id' => 1,
        'created_at' => now(),
    ]));

    $admin = User::query()->create([
        'first_name' => 'Vendor',
        'last_name' => 'Admin',
        'email' => 'repair-admin-'.uniqid().'@example.test',
        'cell_phone' => '224'.rand(1000000, 9999999),
        'password' => bcrypt('password'),
    ]);
    $vendor->users()->attach($admin->id, ['is_employed' => true, 'role_id' => 1]);

    $source->forceFill([
        'service_availability' => [
            'slots' => [
                ['date' => now()->addDays(4)->format('Y-m-d'), 'time' => 'Anytime'],
                ['date' => now()->addDays(5)->format('Y-m-d'), 'time' => '7-9 AM'],
                ['date' => now()->addDays(6)->format('Y-m-d'), 'time' => '11-1 PM'],
            ],
            'task_ids' => [],
            'submitted_at' => now()->toIso8601String(),
        ],
    ])->save();

    $notification = AppNotification::create([
        'user_id' => $admin->id,
        'type' => 'service_availability_submitted',
        'title' => 'A homeowner shared preferred times',
        'body' => 'Preferred service times submitted. Schedule the service call.',
        'action_url' => route('projects.show', $source),
        'data' => ['project_id' => $source->id, 'task_id' => null],
    ]);

    return compact('source', 'target', 'task', 'notification', 'admin');
}

it('dry-run reports the move and changes nothing', function (): void {
    ['source' => $source, 'target' => $target, 'notification' => $notification] = repairCommandClientProjects();

    $this->artisan('service-availability:repair', ['--dry-run' => true])
        ->expectsOutputToContain("project {$source->id} -> project {$target->id}")
        ->expectsOutputToContain('1 would be moved')
        ->assertSuccessful();

    expect($source->fresh()->service_availability)->not->toBeNull();
    expect($target->fresh()->service_availability)->toBeNull();
    expect($notification->fresh()->action_url)->toBe(route('projects.show', $source));
});

it('--apply moves the availability and re-points the notification', function (): void {
    ['source' => $source, 'target' => $target, 'task' => $task, 'notification' => $notification] = repairCommandClientProjects();

    $this->artisan('service-availability:repair', ['--apply' => true])
        ->expectsOutputToContain("project {$source->id} -> project {$target->id}")
        ->expectsOutputToContain('1 moved.')
        ->assertSuccessful();

    expect($source->fresh()->service_availability)->toBeNull();

    $target->refresh();
    expect($target->service_availability['slots'])->toHaveCount(3);
    expect($target->service_availability['task_ids'])->toBe([$task->id]);

    $notification->refresh();
    expect($notification->data['project_id'])->toBe($target->id)
        ->and($notification->data['task_id'])->toBe($task->id)
        ->and($notification->action_url)->toBe(route('planner.cards', ['task' => $task->id]));
});

it('a second --apply is a no-op', function (): void {
    ['source' => $source, 'target' => $target] = repairCommandClientProjects();

    $this->artisan('service-availability:repair', ['--apply' => true])->assertSuccessful();

    $targetAfterFirstRun = $target->fresh()->service_availability;

    $this->artisan('service-availability:repair', ['--apply' => true])
        ->expectsOutputToContain('Nothing to repair')
        ->assertSuccessful();

    expect($target->fresh()->service_availability)->toBe($targetAfterFirstRun);
    expect($source->fresh()->service_availability)->toBeNull();
});

it('--dry-run wins when both --apply and --dry-run are given', function (): void {
    ['source' => $source, 'target' => $target] = repairCommandClientProjects();

    $this->artisan('service-availability:repair', ['--apply' => true, '--dry-run' => true])
        ->expectsOutputToContain('1 would be moved')
        ->assertSuccessful();

    expect($source->fresh()->service_availability)->not->toBeNull();
    expect($target->fresh()->service_availability)->toBeNull();
});

it('--project limits the run to one source project', function (): void {
    ['source' => $source1, 'target' => $target1] = repairCommandClientProjects();
    ['source' => $source2] = repairCommandClientProjects();

    $this->artisan('service-availability:repair', ['--dry-run' => true, '--project' => $source1->id])
        ->expectsOutputToContain("project {$source1->id} -> project {$target1->id}")
        ->expectsOutputToContain('1 would be moved')
        ->assertSuccessful();

    // The other pair's source is untouched either way (dry-run never
    // writes), confirming it is also simply out of scope for this run.
    expect($source2->fresh()->service_availability)->not->toBeNull();
});

it('skips a target that already has a newer submission of its own', function (): void {
    ['source' => $source, 'target' => $target] = repairCommandClientProjects();

    $target->forceFill([
        'service_availability' => [
            'slots' => [
                ['date' => now()->addDays(10)->format('Y-m-d'), 'time' => 'Anytime'],
            ],
            'task_ids' => [],
            'submitted_at' => now()->addMinute()->toIso8601String(),
        ],
    ])->save();

    $this->artisan('service-availability:repair', ['--apply' => true])
        ->expectsOutputToContain('SKIPPED, target already has a newer submission')
        ->assertSuccessful();

    expect($source->fresh()->service_availability)->not->toBeNull();
    expect($target->fresh()->service_availability['slots'])->toHaveCount(1);
});
