<?php

use App\Livewire\Vendor\AvailabilityIndex;
use App\Livewire\Vendors\VendorTaskList;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * A GC company (vendor + admin + project of their own), all built without
 * an authenticated user so ProjectObserver/TaskObserver's auth()->user()
 * stamping doesn't apply — fields set explicitly instead.
 */
function sec1_avail_gc(string $label): array
{
    $vendor = Vendor::factory()->create(['business_name' => "GC {$label}"]);
    $vendor->forceFill(['registration' => ['registered' => true]])->save();

    $user = new User();
    $user->forceFill([
        'first_name' => $label,
        'last_name' => 'Admin',
        'email' => 'sec1-avail-' . strtolower($label) . '-' . uniqid() . '@example.test',
        'cell_phone' => fake()->unique()->numerify('224555####'),
        'primary_vendor_id' => $vendor->id,
        'registration' => ['registered' => true],
    ]);
    $user->save();
    $vendor->users()->attach($user->id, ['role_id' => 1]);

    $client = Client::factory()->create();

    $project = Project::withoutEvents(fn () => Project::create([
        'project_name' => "{$label} Project",
        'client_id' => $client->id,
        'address' => '1 Main St',
        'city' => 'Chicago',
        'state' => 'IL',
        'zip_code' => '60601',
        'belongs_to_vendor_id' => $vendor->id,
    ]));
    $project->vendors()->attach($vendor->id, ['client_id' => $client->id]);

    return compact('vendor', 'user', 'client', 'project');
}

// ── VendorTaskList: a sub shared by two GCs ──────────────────────────────

it('does not show another GC\'s tasks for a sub-vendor shared between two companies', function (): void {
    $gcA = sec1_avail_gc('TlA');
    $gcB = sec1_avail_gc('TlB');
    $sub = Vendor::factory()->create(['business_name' => 'Shared Sub']);

    $taskA = Task::withoutEvents(fn () => Task::create([
        'title' => 'A\'s task for the sub', 'type' => 'Task', 'order' => 1,
        'project_id' => $gcA['project']->id, 'vendor_id' => $sub->id,
        'belongs_to_vendor_id' => $gcA['vendor']->id, 'created_by_user_id' => $gcA['user']->id,
        'start_date' => now()->addDay(), 'end_date' => now()->addDay(),
    ]));
    $taskB = Task::withoutEvents(fn () => Task::create([
        'title' => 'B\'s task for the sub', 'type' => 'Task', 'order' => 1,
        'project_id' => $gcB['project']->id, 'vendor_id' => $sub->id,
        'belongs_to_vendor_id' => $gcB['vendor']->id, 'created_by_user_id' => $gcB['user']->id,
        'start_date' => now()->addDay(), 'end_date' => now()->addDay(),
    ]));

    $component = Livewire::actingAs($gcA['user'])
        ->test(VendorTaskList::class, ['vendor' => $sub]);

    $visibleIds = $component->instance()->groupedTasks()
        ->flatten(1)
        ->pluck('id')
        ->all();

    expect($visibleIds)->toContain($taskA->id)
        ->not->toContain($taskB->id);
});

it('shows a GC its own tasks for a shared sub-vendor', function (): void {
    $gcA = sec1_avail_gc('TlC');
    $sub = Vendor::factory()->create(['business_name' => 'Sub for C']);

    $task = Task::withoutEvents(fn () => Task::create([
        'title' => 'C\'s task', 'type' => 'Task', 'order' => 1,
        'project_id' => $gcA['project']->id, 'vendor_id' => $sub->id,
        'belongs_to_vendor_id' => $gcA['vendor']->id, 'created_by_user_id' => $gcA['user']->id,
        'start_date' => now()->addDay(), 'end_date' => now()->addDay(),
    ]));

    $component = Livewire::actingAs($gcA['user'])
        ->test(VendorTaskList::class, ['vendor' => $sub]);

    $visibleIds = $component->instance()->groupedTasks()->flatten(1)->pluck('id')->all();

    expect($visibleIds)->toContain($task->id);
});

// ── AvailabilityIndex: proposingTaskId ───────────────────────────────────

it('locks AvailabilityIndex proposingTaskId against direct client writes', function (): void {
    $vendor = Vendor::factory()->create(['availability_token' => 'sec1-lock-token']);

    $component = Livewire::test(AvailabilityIndex::class, ['token' => 'sec1-lock-token']);

    expect(fn () => $component->set('proposingTaskId', 999999))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('does not leak another vendor\'s homeowner preferred slots for a foreign task id', function (): void {
    $gcA = sec1_avail_gc('AvA');
    $gcB = sec1_avail_gc('AvB');
    $subA = Vendor::factory()->create(['availability_token' => 'sec1-token-a']);
    $subB = Vendor::factory()->create(['availability_token' => 'sec1-token-b']);

    $taskA = Task::withoutEvents(fn () => Task::create([
        'title' => 'A\'s task', 'type' => 'task', 'order' => 1,
        'project_id' => $gcA['project']->id, 'vendor_id' => $subA->id,
        'belongs_to_vendor_id' => $gcA['vendor']->id, 'created_by_user_id' => $gcA['user']->id,
    ]));
    $taskB = Task::withoutEvents(fn () => Task::create([
        'title' => 'B\'s task', 'type' => 'task', 'order' => 1,
        'project_id' => $gcB['project']->id, 'vendor_id' => $subB->id,
        'belongs_to_vendor_id' => $gcB['vendor']->id, 'created_by_user_id' => $gcB['user']->id,
    ]));

    $gcB['project']->forceFill([
        'service_availability' => [
            'slots' => [['date' => '2026-07-08', 'time' => 'Anytime']],
            'submitted_at' => now()->toIso8601String(),
            'task_ids' => [$taskB->id],
        ],
    ])->saveQuietly();

    // Holder of vendor A's own valid availability link.
    $instance = Livewire::test(AvailabilityIndex::class, ['token' => 'sec1-token-a'])->instance();

    // Locked blocks the normal client update path; force the property via
    // reflection to prove the belt-and-suspenders vendor_id check inside
    // proposedPreferredSlots()/clearAppliedPreferredSlots() holds even if
    // that lock were ever bypassed some other way.
    $property = new ReflectionProperty($instance, 'proposingTaskId');
    $property->setAccessible(true);
    $property->setValue($instance, $taskB->id);

    expect($instance->proposedPreferredSlots())->toBe([]);
});

it('shows a vendor its own task\'s homeowner preferred slots', function (): void {
    $gc = sec1_avail_gc('AvC');
    $sub = Vendor::factory()->create(['availability_token' => 'sec1-token-c']);

    $task = Task::withoutEvents(fn () => Task::create([
        'title' => 'C\'s task', 'type' => 'task', 'order' => 1,
        'project_id' => $gc['project']->id, 'vendor_id' => $sub->id,
        'belongs_to_vendor_id' => $gc['vendor']->id, 'created_by_user_id' => $gc['user']->id,
    ]));

    $gc['project']->forceFill([
        'service_availability' => [
            'slots' => [['date' => '2026-07-08', 'time' => 'Anytime']],
            'submitted_at' => now()->toIso8601String(),
            'task_ids' => [$task->id],
        ],
    ])->saveQuietly();

    $slots = Livewire::test(AvailabilityIndex::class, ['token' => 'sec1-token-c'])
        ->call('openProposeDatesModal', $task->id)
        ->instance()
        ->proposedPreferredSlots();

    expect($slots)->toHaveCount(1)
        ->and($slots[0]['date'])->toBe('2026-07-08');
});
