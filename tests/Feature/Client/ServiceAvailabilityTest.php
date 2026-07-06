<?php

use App\Livewire\Client\ScheduleIndex;
use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\Task;
use App\Models\User;
use App\Models\Vendor;
use App\Notifications\ClientServiceAvailabilityNotification;
use App\Notifications\VendorClientTimesRequestNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// Pin the clock to a Thursday so the first three selectable service days
// (which start 4 days out) always land on weekdays. Individual tests may
// override the time (e.g. overnight scenarios) before resetting.
beforeEach(function (): void {
    Carbon\Carbon::setTestNow(Carbon\Carbon::parse('2026-07-09 10:00:00', 'America/Chicago'));
});

afterEach(function (): void {
    Carbon\Carbon::setTestNow();
});

function makeServiceCallProject(int $statusCode = 8): Project
{
    $client = Client::factory()->create(['business_name' => 'Homeowner Household']);
    $vendor = Vendor::factory()->create(['business_name' => 'GS Construction']);

    $project = Project::withoutEvents(fn () => Project::create([
        'project_name' => 'Service Call Project',
        'client_id' => $client->id,
        'belongs_to_vendor_id' => $vendor->id,
        'address' => '100 Main St',
        'city' => 'Cary',
        'state' => 'IL',
        'zip_code' => '60013',
    ]));

    $project->forceFill(['schedule_token' => 'service-call-token'])->saveQuietly();

    ProjectStatus::withoutEvents(fn () => ProjectStatus::create([
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $vendor->id,
        'status_code' => $statusCode,
        'start_date' => now(),
    ]));

    return $project->fresh();
}

it('shows the preferred service times picker for a Service Call project', function (): void {
    $project = makeServiceCallProject(8);

    Task::withoutEvents(fn () => Task::create([
        'title' => 'Service Task',
        'type' => 'task',
        'order' => 1,
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $project->belongs_to_vendor_id,
        'created_by_user_id' => 1,
        'vendor_status' => Task::VENDOR_STATUS_REQUESTED,
        'created_at' => now(),
    ]));

    Livewire::test(ScheduleIndex::class, ['token' => $project->schedule_token])
        ->assertSet('valid', true)
        ->assertSee('Preferred Service Times');
});

it('does not show the preferred service times picker when there are no unscheduled tasks', function (): void {
    $project = makeServiceCallProject(8);

    Livewire::test(ScheduleIndex::class, ['token' => $project->schedule_token])
        ->assertSet('valid', true)
        ->assertDontSee('Preferred Service Times');
});

it('does not show the picker for a non Service Call project', function (): void {
    $project = makeServiceCallProject(6); // Active

    Livewire::test(ScheduleIndex::class, ['token' => $project->schedule_token])
        ->assertSet('valid', true)
        ->assertDontSee('Preferred Service Times');
});

it('stores the selection and notifies the vendor when submitting valid times', function (): void {
    Notification::fake();

    $project = makeServiceCallProject(8);
    $vendor = $project->createdByVendor;

    $admin = User::query()->create([
        'first_name' => 'Vendor',
        'last_name' => 'Admin',
        'email' => 'vendor-admin@example.test',
        'cell_phone' => '2245550100',
        'password' => bcrypt('password'),
    ]);
    $vendor->users()->attach($admin->id, ['is_employed' => true, 'role_id' => 1]);

    $component = Livewire::test(ScheduleIndex::class, ['token' => $project->schedule_token]);
    $days = $component->instance()->serviceDays;

    $component
        ->call('toggleServiceSlot', $days[0], '7-9 AM')
        ->call('toggleServiceSlot', $days[1], '11-1 PM')
        ->call('toggleServiceSlot', $days[2], 'Anytime')
        ->assertSet('serviceSelectionMeetsMinimum', true)
        ->call('submitServiceAvailability')
        ->assertSet('serviceAvailabilitySubmitted', true);

    $project->refresh();

    expect($project->service_availability['slots'])->toHaveCount(3);
    expect(collect($project->service_availability['slots'])->pluck('time'))
        ->toContain('7-9 AM', '11-1 PM', 'Anytime');

    Notification::assertSentTo($admin, ClientServiceAvailabilityNotification::class);
});

it('adds a Hive Hub notification for GC admins when the homeowner submits times', function (): void {
    Notification::fake();

    $project = makeServiceCallProject(8);
    $vendor = $project->createdByVendor;

    $admin = User::query()->create([
        'first_name' => 'Vendor',
        'last_name' => 'Admin',
        'email' => 'vendor-admin-hub@example.test',
        'cell_phone' => '2245550109',
        'password' => bcrypt('password'),
    ]);
    $vendor->users()->attach($admin->id, ['is_employed' => true, 'role_id' => 1]);

    $component = Livewire::test(ScheduleIndex::class, ['token' => $project->schedule_token]);
    $days = $component->instance()->serviceDays;

    $component
        ->call('toggleServiceSlot', $days[0], '7-9 AM')
        ->call('toggleServiceSlot', $days[1], '11-1 PM')
        ->call('toggleServiceSlot', $days[2], 'Anytime')
        ->call('submitServiceAvailability')
        ->assertSet('serviceAvailabilitySubmitted', true);

    $this->assertDatabaseHas('app_notifications', [
        'user_id' => $admin->id,
        'type' => 'service_availability_submitted',
    ]);
});

it('defers the GC admin text to business hours when submitted overnight', function (): void {
    Notification::fake();
    Carbon\Carbon::setTestNow(Carbon\Carbon::parse('2026-07-09 02:30:00', 'America/Chicago'));

    $project = makeServiceCallProject(8);
    $vendor = $project->createdByVendor;

    $admin = User::query()->create([
        'first_name' => 'Vendor',
        'last_name' => 'Admin',
        'email' => 'vendor-admin-overnight@example.test',
        'cell_phone' => '2245550100',
        'password' => bcrypt('password'),
    ]);
    $vendor->users()->attach($admin->id, ['is_employed' => true, 'role_id' => 1]);

    $component = Livewire::test(ScheduleIndex::class, ['token' => $project->schedule_token]);
    $days = $component->instance()->serviceDays;

    $component
        ->call('toggleServiceSlot', $days[0], '7-9 AM')
        ->call('toggleServiceSlot', $days[1], '11-1 PM')
        ->call('toggleServiceSlot', $days[2], 'Anytime')
        ->call('submitServiceAvailability')
        ->assertSet('serviceAvailabilitySubmitted', true);

    Notification::assertSentTo(
        $admin,
        ClientServiceAvailabilityNotification::class,
        function (ClientServiceAvailabilityNotification $notification): bool {
            return $notification->delay !== null
                && Carbon\Carbon::parse($notification->delay)->format('H') === '07';
        }
    );

    Carbon\Carbon::setTestNow();
});

it('texts the task vendor to select availability when the client submits times', function (): void {
    Notification::fake();
    Carbon\Carbon::setTestNow(Carbon\Carbon::parse('2026-07-09 10:00:00', 'America/Chicago'));

    $project = makeServiceCallProject(8);

    $taskVendor = Vendor::factory()->create([
        'business_name' => 'Smartech Electrical',
        'business_phone' => '2245550111',
    ]);

    $task = Task::withoutEvents(fn () => Task::create([
        'title' => 'Fix Electrical Outlet',
        'type' => 'task',
        'order' => 1,
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $project->belongs_to_vendor_id,
        'vendor_id' => $taskVendor->id,
        'created_by_user_id' => 1,
        'created_at' => now(),
    ]));

    $component = Livewire::test(ScheduleIndex::class, ['token' => $project->schedule_token]);
    $days = $component->instance()->serviceDays;

    $component
        ->call('toggleServiceSlot', $days[0], '7-9 AM')
        ->call('toggleServiceSlot', $days[1], '11-1 PM')
        ->call('toggleServiceSlot', $days[2], 'Anytime')
        ->call('submitServiceAvailability')
        ->assertSet('serviceAvailabilitySubmitted', true);

    Notification::assertSentTo(
        $taskVendor,
        VendorClientTimesRequestNotification::class,
        function (VendorClientTimesRequestNotification $notification) use ($taskVendor): bool {
            return $notification->tasks->first()?->vendor_id === $taskVendor->id
                && $notification->tasks->contains(fn ($task) => $task->title === 'Fix Electrical Outlet')
                && $notification->delay === null;
        }
    );

    expect($task->fresh()->vendor_status)->toBe(Task::VENDOR_STATUS_REQUESTED);

    Carbon\Carbon::setTestNow();
});

it('defers the vendor text to business hours when submitted overnight', function (): void {
    Notification::fake();
    Carbon\Carbon::setTestNow(Carbon\Carbon::parse('2026-07-09 02:30:00', 'America/Chicago'));

    $project = makeServiceCallProject(8);

    $taskVendor = Vendor::factory()->create([
        'business_name' => 'Smartech Electrical',
        'business_phone' => '2245550111',
    ]);

    Task::withoutEvents(fn () => Task::create([
        'title' => 'Fix Electrical Outlet',
        'type' => 'task',
        'order' => 1,
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $project->belongs_to_vendor_id,
        'vendor_id' => $taskVendor->id,
        'created_by_user_id' => 1,
        'created_at' => now(),
    ]));

    $component = Livewire::test(ScheduleIndex::class, ['token' => $project->schedule_token]);
    $days = $component->instance()->serviceDays;

    $component
        ->call('toggleServiceSlot', $days[0], '7-9 AM')
        ->call('toggleServiceSlot', $days[1], '11-1 PM')
        ->call('toggleServiceSlot', $days[2], 'Anytime')
        ->call('submitServiceAvailability')
        ->assertSet('serviceAvailabilitySubmitted', true);

    Notification::assertSentTo(
        $taskVendor,
        VendorClientTimesRequestNotification::class,
        function (VendorClientTimesRequestNotification $notification): bool {
            return $notification->delay !== null
                && Carbon\Carbon::parse($notification->delay)->format('H') === '07';
        }
    );

    Carbon\Carbon::setTestNow();
});

it('does not text a task vendor when the task is already scheduled', function (): void {
    Notification::fake();

    $project = makeServiceCallProject(8);

    $taskVendor = Vendor::factory()->create([
        'business_name' => 'Smartech Electrical',
        'business_phone' => '2245550111',
    ]);

    Task::withoutEvents(fn () => Task::create([
        'title' => 'Already Scheduled',
        'type' => 'task',
        'order' => 1,
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $project->belongs_to_vendor_id,
        'vendor_id' => $taskVendor->id,
        'created_by_user_id' => 1,
        'start_date' => now()->addDays(5),
        'end_date' => now()->addDays(5),
        'created_at' => now(),
    ]));

    // A separate unscheduled task with no vendor keeps the picker available.
    Task::withoutEvents(fn () => Task::create([
        'title' => 'Unassigned Pending',
        'type' => 'task',
        'order' => 2,
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $project->belongs_to_vendor_id,
        'created_by_user_id' => 1,
        'vendor_status' => Task::VENDOR_STATUS_REQUESTED,
        'created_at' => now(),
    ]));

    $component = Livewire::test(ScheduleIndex::class, ['token' => $project->schedule_token]);
    $days = $component->instance()->serviceDays;

    $component
        ->call('toggleServiceSlot', $days[0], '7-9 AM')
        ->call('toggleServiceSlot', $days[1], '11-1 PM')
        ->call('toggleServiceSlot', $days[2], 'Anytime')
        ->call('submitServiceAvailability')
        ->assertSet('serviceAvailabilitySubmitted', true);

    Notification::assertNotSentTo($taskVendor, VendorClientTimesRequestNotification::class);
});

it('does not submit when fewer than 3 days are selected', function (): void {
    Notification::fake();

    $project = makeServiceCallProject(8);

    $component = Livewire::test(ScheduleIndex::class, ['token' => $project->schedule_token]);
    $day = $component->instance()->serviceDays[0];

    $component
        ->call('toggleServiceSlot', $day, '7-9 AM')
        ->call('toggleServiceSlot', $day, '11-1 PM')
        ->call('toggleServiceSlot', $day, '1-3 PM')
        ->assertSet('serviceSelectionMeetsMinimum', false)
        ->call('submitServiceAvailability')
        ->assertSet('serviceAvailabilitySubmitted', false);

    expect($project->fresh()->service_availability)->toBeNull();
    Notification::assertNothingSent();
});

it('toggling a selected slot removes it', function (): void {
    $project = makeServiceCallProject(8);
    $component = Livewire::test(ScheduleIndex::class, ['token' => $project->schedule_token]);
    $day = $component->instance()->serviceDays[0];

    $component
        ->call('toggleServiceSlot', $day, '7-9 AM')
        ->assertSet('selectedServiceSlots', [$day . '|7-9 AM'])
        ->call('toggleServiceSlot', $day, '7-9 AM')
        ->assertSet('selectedServiceSlots', []);
});

it('replaces specific times with Anytime on the same day', function (): void {
    $project = makeServiceCallProject(8);
    $component = Livewire::test(ScheduleIndex::class, ['token' => $project->schedule_token]);
    $day = $component->instance()->serviceDays[0];

    $component
        ->call('toggleServiceSlot', $day, '7-9 AM')
        ->call('toggleServiceSlot', $day, '11-1 PM')
        ->call('toggleServiceSlot', $day, 'Anytime')
        ->assertSet('selectedServiceSlots', [$day . '|Anytime']);
});

it('removes Anytime when a specific time is selected', function (): void {
    $project = makeServiceCallProject(8);
    $component = Livewire::test(ScheduleIndex::class, ['token' => $project->schedule_token]);
    $day = $component->instance()->serviceDays[0];

    $component
        ->call('toggleServiceSlot', $day, 'Anytime')
        ->assertSet('selectedServiceSlots', [$day . '|Anytime'])
        ->call('toggleServiceSlot', $day, '7-9 AM')
        ->assertSet('selectedServiceSlots', [$day . '|7-9 AM']);
});

it('shows weekends in the calendar flow but keeps them disabled', function (): void {
    $project = makeServiceCallProject(8);

    $component = Livewire::test(ScheduleIndex::class, ['token' => $project->schedule_token]);
    $days = $component->instance()->serviceDays;

    expect($days)->toHaveCount(14);
    expect(collect($days)->filter(fn (string $day) => \Carbon\Carbon::parse($day)->isWeekend()))->not->toBeEmpty();

    $weekendDay = collect($days)->first(fn (string $day) => \Carbon\Carbon::parse($day)->isWeekend());

    $component
        ->call('toggleServiceSlot', $weekendDay, '7-9 AM')
        ->assertSet('selectedServiceSlots', []);
});

it('requires new preferred times when pending tasks changed after submission', function (): void {
    $project = makeServiceCallProject(8);

    $project->forceFill([
        'service_availability' => [
            'slots' => [
                ['date' => '2026-07-06', 'time' => '7-9 AM'],
                ['date' => '2026-07-08', 'time' => '3-5 PM'],
                ['date' => '2026-07-10', 'time' => 'Anytime'],
            ],
            'submitted_at' => now()->subDay()->toIso8601String(),
        ],
    ])->saveQuietly();

    Task::withoutEvents(fn () => Task::create([
        'title' => 'Misc Items 2',
        'type' => 'task',
        'order' => 1,
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $project->belongs_to_vendor_id,
        'created_by_user_id' => 1,
        'vendor_status' => Task::VENDOR_STATUS_REQUESTED,
        'created_at' => now(),
    ]));

    Livewire::test(ScheduleIndex::class, ['token' => $project->schedule_token])
        ->assertSet('serviceAvailabilitySubmitted', false)
        ->assertSet('selectedServiceSlots', []);
});

it('shows Service Scheduled status on scheduled tasks matching homeowner preferred times', function (): void {
    $project = makeServiceCallProject(8);

    $slotDate = now()->addDays(4)->format('Y-m-d');

    $project->forceFill([
        'service_availability' => [
            'slots' => [
                ['date' => $slotDate, 'time' => '3-5 PM'],
            ],
            'submitted_at' => now()->toIso8601String(),
            'task_ids' => [],
        ],
    ])->saveQuietly();

    Task::withoutEvents(fn () => Task::create([
        'title' => 'Misc Items',
        'type' => 'task',
        'order' => 1,
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $project->belongs_to_vendor_id,
        'created_by_user_id' => 1,
        'start_date' => $slotDate,
        'end_date' => $slotDate,
        'options' => [
            'dates' => [$slotDate],
            'time_settings' => [
                $slotDate => [
                    'use_time' => true,
                    'start_time' => '15:00',
                    'end_time' => '17:00',
                ],
            ],
        ],
    ]));

    Livewire::test(ScheduleIndex::class, ['token' => $project->schedule_token])
        ->assertSee('Service Scheduled');
});

it('hides picker tasks the homeowner already submitted times for', function (): void {
    $project = makeServiceCallProject(8);

    $pendingTask = Task::withoutEvents(fn () => Task::create([
        'title' => 'Fix Electrical Outlet',
        'type' => 'task',
        'order' => 1,
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $project->belongs_to_vendor_id,
        'created_by_user_id' => 1,
        'vendor_status' => Task::VENDOR_STATUS_REQUESTED,
        'created_at' => now()->subMinute(),
    ]));

    $project->forceFill([
        'service_availability' => [
            'slots' => [
                ['date' => now()->addDays(3)->format('Y-m-d'), 'time' => '7-9 AM'],
                ['date' => now()->addDays(4)->format('Y-m-d'), 'time' => '9-11 AM'],
                ['date' => now()->addDays(5)->format('Y-m-d'), 'time' => '1-3 PM'],
            ],
            'submitted_at' => now()->toIso8601String(),
            'task_ids' => [$pendingTask->id],
        ],
    ])->saveQuietly();

    $component = Livewire::test(ScheduleIndex::class, ['token' => $project->schedule_token]);

    expect($component->instance()->pickerPendingTasks->pluck('id')->all())->toBe([]);
    expect($component->instance()->unscheduledTasks->pluck('id')->all())->toBe([$pendingTask->id]);
});

it('keeps only tasks needing times in the picker after a new task is added', function (): void {
    $project = makeServiceCallProject(8);

    $coveredTask = Task::withoutEvents(fn () => Task::create([
        'title' => 'Fix Electrical Outlet',
        'type' => 'task',
        'order' => 1,
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $project->belongs_to_vendor_id,
        'created_by_user_id' => 1,
        'vendor_status' => Task::VENDOR_STATUS_REQUESTED,
        'created_at' => now()->subMinutes(2),
    ]));

    $newTask = Task::withoutEvents(fn () => Task::create([
        'title' => 'Fix Cabinet',
        'type' => 'task',
        'order' => 2,
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $project->belongs_to_vendor_id,
        'created_by_user_id' => 1,
        'vendor_status' => Task::VENDOR_STATUS_REQUESTED,
        'created_at' => now(),
    ]));

    $project->forceFill([
        'service_availability' => [
            'slots' => [
                ['date' => now()->addDays(3)->format('Y-m-d'), 'time' => '7-9 AM'],
                ['date' => now()->addDays(4)->format('Y-m-d'), 'time' => '9-11 AM'],
                ['date' => now()->addDays(5)->format('Y-m-d'), 'time' => '1-3 PM'],
            ],
            'submitted_at' => now()->toIso8601String(),
            'task_ids' => [$coveredTask->id],
        ],
    ])->saveQuietly();

    $component = Livewire::test(ScheduleIndex::class, ['token' => $project->schedule_token]);

    expect($component->instance()->pickerPendingTasks->pluck('id')->all())->toBe([$newTask->id]);
    expect($component->instance()->unscheduledTasks->pluck('id')->all())
        ->toBe([$coveredTask->id, $newTask->id]);
});

it('renders pending tasks with the shared task card component', function (): void {
    $project = makeServiceCallProject(8);

    Task::withoutEvents(fn () => Task::create([
        'title' => 'Misc Items 2',
        'type' => 'task',
        'order' => 1,
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $project->belongs_to_vendor_id,
        'created_by_user_id' => 1,
        'vendor_status' => Task::VENDOR_STATUS_REQUESTED,
        'created_at' => now(),
    ]));

    Livewire::test(ScheduleIndex::class, ['token' => $project->schedule_token])
        ->assertSee('Misc Items 2')
        ->assertDontSee('border-b border-zinc-200 pb-2 dark:border-zinc-700');
});

