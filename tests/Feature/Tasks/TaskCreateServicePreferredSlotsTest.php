<?php

use App\Livewire\Tasks\TaskCreate;
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
 * @return array{vendor: Vendor, project: Project}
 */
function makeServicePreferredFixture(array $slots): array
{
    $vendor = Vendor::factory()->create(['business_name' => 'GS Construction']);

    $user = User::query()->create([
        'first_name' => 'Owner',
        'last_name' => 'User',
        'email' => 'owner.pref-' . uniqid() . '@example.com',
        'cell_phone' => (string) random_int(2000000000, 9999999999),
        'primary_vendor_id' => $vendor->id,
    ]);
    $vendor->users()->attach($user->id, ['is_employed' => true, 'role_id' => 1]);

    test()->actingAs($user);

    $client = Client::factory()->create();
    $client->vendors()->attach($vendor->id);

    $project = Project::query()->create([
        'project_name' => 'Project ' . uniqid(),
        'client_id' => $client->id,
        'belongs_to_vendor_id' => $vendor->id,
        'address' => '123 Main St',
        'city' => 'Chicago',
        'state' => 'IL',
        'zip_code' => 60601,
        'service_availability' => [
            'slots' => $slots,
            'submitted_at' => now()->toIso8601String(),
        ],
    ]);

    $project->vendors()->attach($vendor->id, ['client_id' => $client->id]);

    return ['vendor' => $vendor, 'project' => $project];
}

it('exposes the homeowner preferred slots grouped by day for the schedule picker', function (): void {
    ['project' => $project] = makeServicePreferredFixture([
        ['date' => '2026-07-16', 'time' => '7-9 AM'],
        ['date' => '2026-07-16', 'time' => '1-3 PM'],
        ['date' => '2026-07-20', 'time' => 'Anytime'],
    ]);

    $slots = Livewire::test(TaskCreate::class)
        ->set('form.project_id', $project->id)
        ->instance()
        ->servicePreferredSlots();

    expect($slots)->toHaveCount(2)
        ->and($slots[0]['date'])->toBe('2026-07-16')
        ->and(collect($slots[0]['times'])->pluck('time')->all())->toBe(['7-9 AM', '1-3 PM'])
        ->and($slots[1]['date'])->toBe('2026-07-20')
        ->and(collect($slots[1]['times'])->pluck('time')->all())->toBe(['Anytime']);
});

it('returns no preferred slots when the project has none', function (): void {
    ['project' => $project] = makeServicePreferredFixture([]);

    $slots = Livewire::test(TaskCreate::class)
        ->set('form.project_id', $project->id)
        ->instance()
        ->servicePreferredSlots();

    expect($slots)->toBe([]);
});

it('hides preferred slots when editing an already-scheduled task', function (): void {
    ['project' => $project, 'vendor' => $vendor] = makeServicePreferredFixture([
        ['date' => '2026-07-16', 'time' => '1-3 PM'],
    ]);

    $task = Task::withoutEvents(fn () => Task::query()->create([
        'title' => 'Scheduled task',
        'project_id' => $project->id,
        'vendor_id' => $vendor->id,
        'belongs_to_vendor_id' => $vendor->id,
        'created_by_user_id' => auth()->id(),
        'type' => 'Task',
        'order' => 0,
        'start_date' => '2026-06-30',
        'end_date' => '2026-06-30',
    ]));

    $slots = Livewire::test(TaskCreate::class)
        ->call('editTask', $task->id)
        ->instance()
        ->servicePreferredSlots();

    expect($slots)->toBe([]);
});

it('shows preferred slots when editing an unscheduled task', function (): void {
    ['project' => $project, 'vendor' => $vendor] = makeServicePreferredFixture([
        ['date' => '2026-07-16', 'time' => '1-3 PM'],
    ]);

    $task = Task::withoutEvents(fn () => Task::query()->create([
        'title' => 'Pending task',
        'project_id' => $project->id,
        'vendor_id' => $vendor->id,
        'belongs_to_vendor_id' => $vendor->id,
        'created_by_user_id' => auth()->id(),
        'type' => 'Task',
        'order' => 0,
    ]));

    $slots = Livewire::test(TaskCreate::class)
        ->call('editTask', $task->id)
        ->instance()
        ->servicePreferredSlots();

    expect($slots)->toHaveCount(1)
        ->and($slots[0]['date'])->toBe('2026-07-16');
});

it('hides preferred slots when saved availability is stale for current pending tasks', function (): void {
    ['project' => $project, 'vendor' => $vendor] = makeServicePreferredFixture([
        ['date' => '2026-07-16', 'time' => '1-3 PM'],
    ]);

    $firstTask = Task::withoutEvents(fn () => Task::query()->create([
        'title' => 'Older pending task',
        'project_id' => $project->id,
        'vendor_id' => $vendor->id,
        'belongs_to_vendor_id' => $vendor->id,
        'created_by_user_id' => auth()->id(),
        'type' => 'Task',
        'order' => 0,
        'created_at' => now()->subHour(),
    ]));

    $project->forceFill([
        'service_availability' => [
            'slots' => [
                ['date' => '2026-07-16', 'time' => '1-3 PM'],
            ],
            'task_ids' => [$firstTask->id],
            'submitted_at' => now()->subMinutes(30)->toIso8601String(),
        ],
    ])->saveQuietly();

    Task::withoutEvents(fn () => Task::query()->create([
        'title' => 'New pending task',
        'project_id' => $project->id,
        'vendor_id' => $vendor->id,
        'belongs_to_vendor_id' => $vendor->id,
        'created_by_user_id' => auth()->id(),
        'type' => 'Task',
        'order' => 1,
        'created_at' => now(),
    ]));

    $slots = Livewire::test(TaskCreate::class)
        ->set('form.project_id', $project->id)
        ->instance()
        ->servicePreferredSlots();

    expect($slots)->toBe([]);
});

it('shows preferred slots when editing a covered task after a new task is added', function (): void {
    ['project' => $project, 'vendor' => $vendor] = makeServicePreferredFixture([
        ['date' => '2026-07-16', 'time' => '1-3 PM'],
    ]);

    $coveredTask = Task::withoutEvents(fn () => Task::query()->create([
        'title' => 'Covered pending task',
        'project_id' => $project->id,
        'vendor_id' => $vendor->id,
        'belongs_to_vendor_id' => $vendor->id,
        'created_by_user_id' => auth()->id(),
        'type' => 'Task',
        'order' => 0,
        'created_at' => now()->subHour(),
    ]));

    $project->forceFill([
        'service_availability' => [
            'slots' => [
                ['date' => '2026-07-16', 'time' => '1-3 PM'],
            ],
            'task_ids' => [$coveredTask->id],
            'submitted_at' => now()->subMinutes(30)->toIso8601String(),
        ],
    ])->saveQuietly();

    Task::withoutEvents(fn () => Task::query()->create([
        'title' => 'New uncovered task',
        'project_id' => $project->id,
        'vendor_id' => $vendor->id,
        'belongs_to_vendor_id' => $vendor->id,
        'created_by_user_id' => auth()->id(),
        'type' => 'Task',
        'order' => 1,
        'created_at' => now(),
    ]));

    $slots = Livewire::test(TaskCreate::class)
        ->call('editTask', $coveredTask->id)
        ->instance()
        ->servicePreferredSlots();

    expect($slots)->toHaveCount(1)
        ->and($slots[0]['date'])->toBe('2026-07-16');
});

it('hides preferred slots when editing an uncovered task added after submission', function (): void {
    ['project' => $project, 'vendor' => $vendor] = makeServicePreferredFixture([
        ['date' => '2026-07-16', 'time' => '1-3 PM'],
    ]);

    $coveredTask = Task::withoutEvents(fn () => Task::query()->create([
        'title' => 'Covered pending task',
        'project_id' => $project->id,
        'vendor_id' => $vendor->id,
        'belongs_to_vendor_id' => $vendor->id,
        'created_by_user_id' => auth()->id(),
        'type' => 'Task',
        'order' => 0,
        'created_at' => now()->subHour(),
    ]));

    $project->forceFill([
        'service_availability' => [
            'slots' => [
                ['date' => '2026-07-16', 'time' => '1-3 PM'],
            ],
            'task_ids' => [$coveredTask->id],
            'submitted_at' => now()->subMinutes(30)->toIso8601String(),
        ],
    ])->saveQuietly();

    $newTask = Task::withoutEvents(fn () => Task::query()->create([
        'title' => 'New uncovered task',
        'project_id' => $project->id,
        'vendor_id' => $vendor->id,
        'belongs_to_vendor_id' => $vendor->id,
        'created_by_user_id' => auth()->id(),
        'type' => 'Task',
        'order' => 1,
        'created_at' => now(),
    ]));

    $slots = Livewire::test(TaskCreate::class)
        ->call('editTask', $newTask->id)
        ->instance()
        ->servicePreferredSlots();

    expect($slots)->toBe([]);
});

it('shows an awaiting client availability card for service-call tasks when no active preferred slots exist', function (): void {
    ['project' => $project] = makeServicePreferredFixture([]);

    ProjectStatus::query()->create([
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $project->belongs_to_vendor_id,
        'status_code' => 8,
        'start_date' => now(),
    ]);

    Livewire::test(TaskCreate::class)
        ->dispatch('addTask') // hydrates the gated modal body, as the real open does
        ->set('form.project_id', $project->id)
        ->assertSee('Awaiting client availability')
        ->assertSee('Client has not submitted preferred times yet.');
});

it('does not show the awaiting client availability card for non service-call projects', function (): void {
    ['project' => $project] = makeServicePreferredFixture([]);

    ProjectStatus::query()->create([
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $project->belongs_to_vendor_id,
        'status_code' => 6,
        'start_date' => now(),
    ]);

    Livewire::test(TaskCreate::class)
        ->set('form.project_id', $project->id)
        ->assertDontSee('Awaiting client availability');
});

it('applies a preferred time frame to the task dates and arrival time', function (): void {
    ['project' => $project] = makeServicePreferredFixture([
        ['date' => '2026-07-16', 'time' => '1-3 PM'],
    ]);

    $component = Livewire::test(TaskCreate::class)
        ->set('form.project_id', $project->id)
        ->call('applyServicePreferredSlot', '2026-07-16', '1-3 PM');

    expect($component->get('form.dates'))->toBe(['2026-07-16'])
        ->and($component->get('form.time_settings.2026-07-16.use_time'))->toBeTrue()
        ->and($component->get('form.time_settings.2026-07-16.start_time'))->toBe('13:00')
        ->and($component->get('form.time_settings.2026-07-16.end_time'))->toBe('15:00');
});

it('applies an Anytime preference as a date without a specific arrival time', function (): void {
    ['project' => $project] = makeServicePreferredFixture([
        ['date' => '2026-07-20', 'time' => 'Anytime'],
    ]);

    $component = Livewire::test(TaskCreate::class)
        ->set('form.project_id', $project->id)
        ->call('applyServicePreferredSlot', '2026-07-20', 'Anytime');

    expect($component->get('form.dates'))->toBe(['2026-07-20'])
        ->and($component->get('form.time_settings.2026-07-20.use_time'))->toBeFalse();
});

it('toggles a preferred time frame off when applied twice', function (): void {
    ['project' => $project] = makeServicePreferredFixture([
        ['date' => '2026-07-16', 'time' => '7-9 AM'],
    ]);

    $component = Livewire::test(TaskCreate::class)
        ->set('form.project_id', $project->id)
        ->call('applyServicePreferredSlot', '2026-07-16', '7-9 AM')
        ->call('applyServicePreferredSlot', '2026-07-16', '7-9 AM');

    expect($component->get('form.dates'))->toBe([])
        ->and($component->get('form.time_settings'))->not->toHaveKey('2026-07-16');
});

it('allows only one preferred slot at a time, clearing the previous selection', function (): void {
    ['project' => $project] = makeServicePreferredFixture([
        ['date' => '2026-07-16', 'time' => '7-9 AM'],
        ['date' => '2026-07-16', 'time' => '1-3 PM'],
        ['date' => '2026-07-20', 'time' => 'Anytime'],
    ]);

    $component = Livewire::test(TaskCreate::class)
        ->set('form.project_id', $project->id)
        ->call('applyServicePreferredSlot', '2026-07-16', '7-9 AM')
        ->call('applyServicePreferredSlot', '2026-07-20', 'Anytime');

    expect($component->get('form.dates'))->toBe(['2026-07-20'])
        ->and($component->get('form.time_settings'))->not->toHaveKey('2026-07-16')
        ->and($component->get('form.time_settings.2026-07-20.use_time'))->toBeFalse();
});

it('switches between preferred time frames on the same day', function (): void {
    ['project' => $project] = makeServicePreferredFixture([
        ['date' => '2026-07-16', 'time' => '7-9 AM'],
        ['date' => '2026-07-16', 'time' => '1-3 PM'],
    ]);

    $component = Livewire::test(TaskCreate::class)
        ->set('form.project_id', $project->id)
        ->call('applyServicePreferredSlot', '2026-07-16', '7-9 AM')
        ->call('applyServicePreferredSlot', '2026-07-16', '1-3 PM');

    expect($component->get('form.dates'))->toBe(['2026-07-16'])
        ->and($component->get('form.time_settings.2026-07-16.start_time'))->toBe('13:00')
        ->and($component->get('form.time_settings.2026-07-16.end_time'))->toBe('15:00');
});
