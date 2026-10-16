<?php

use App\Livewire\Vendor\AvailabilityIndex;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('shows unscheduled null-status tasks in pending section', function () {
    $ownerVendor = Vendor::factory()->create([
        'business_name' => 'GS Construction',
        'short_name' => 'GS',
        'options' => '{}',
    ]);

    $subjectVendor = Vendor::factory()->create([
        'business_name' => 'Smartech Electric',
        'short_name' => 'SE',
        'availability_token' => 'test-vendor-token',
        'options' => '{}',
    ]);

    $client = Client::factory()->create();

    $project = Project::withoutEvents(fn () => Project::create([
        'project_name' => 'Test Project',
        'client_id' => $client->id,
        'address' => '239 Perth Rd',
        'city' => 'Cary',
        'state' => 'IL',
        'zip_code' => '60013',
        'belongs_to_vendor_id' => $ownerVendor->id,
    ]));

    Task::withoutEvents(fn () => Task::create([
        'title' => 'Fix Electrical',
        'type' => 'task',
        'order' => 1,
        'project_id' => $project->id,
        'vendor_id' => $subjectVendor->id,
        'belongs_to_vendor_id' => $ownerVendor->id,
        'created_by_user_id' => 1,
        'vendor_status' => null,
    ]));

    // The pending card renders through the shared task-card partial now —
    // no "No Date" badge; the Pending section header + CTA carry that meaning.
    Livewire::test(AvailabilityIndex::class, ['token' => $subjectVendor->availability_token])
        ->assertSee('Pending Tasks')
        ->assertSee('Fix Electrical')
        ->assertSee('Set Dates / Schedule');
});

it('opens and saves dates for an unscheduled null-status task', function () {
    $ownerVendor = Vendor::factory()->create([
        'business_name' => 'GS Construction',
        'short_name' => 'GS',
        'options' => '{}',
    ]);

    $subjectVendor = Vendor::factory()->create([
        'business_name' => 'Smartech Electric',
        'short_name' => 'SE',
        'availability_token' => 'test-vendor-token-save',
        'options' => '{}',
    ]);

    $client = Client::factory()->create();

    $project = Project::withoutEvents(fn () => Project::create([
        'project_name' => 'Test Project',
        'client_id' => $client->id,
        'address' => '239 Perth Rd',
        'city' => 'Cary',
        'state' => 'IL',
        'zip_code' => '60013',
        'belongs_to_vendor_id' => $ownerVendor->id,
    ]));

    $task = Task::withoutEvents(fn () => Task::create([
        'title' => 'Fix Electrical',
        'type' => 'task',
        'order' => 1,
        'project_id' => $project->id,
        'vendor_id' => $subjectVendor->id,
        'belongs_to_vendor_id' => $ownerVendor->id,
        'created_by_user_id' => 1,
        'vendor_status' => null,
    ]));

    Livewire::test(AvailabilityIndex::class, ['token' => $subjectVendor->availability_token])
        ->call('openProposeDatesModal', $task->id)
        ->assertSet('proposingTaskId', $task->id)
        ->set('proposedDates', ['2026-05-10'])
        ->call('saveProposedDates');

    $task->refresh();

    expect($task->vendor_status)->toBe(Task::VENDOR_STATUS_CONFIRMED)
        ->and($task->start_date?->format('Y-m-d'))->toBe('2026-05-10')
        ->and($task->end_date?->format('Y-m-d'))->toBe('2026-05-10');
});

it('exposes homeowner submitted times and applies one to the proposal', function () {
    $ownerVendor = Vendor::factory()->create([
        'business_name' => 'GS Construction',
        'short_name' => 'GS',
        'options' => '{}',
    ]);

    $subjectVendor = Vendor::factory()->create([
        'business_name' => 'Smartech Electric',
        'short_name' => 'SE',
        'availability_token' => 'test-vendor-token-preferred',
        'options' => '{}',
    ]);

    $client = Client::factory()->create();

    $project = Project::withoutEvents(fn () => Project::create([
        'project_name' => 'Test Project',
        'client_id' => $client->id,
        'address' => '239 Perth Rd',
        'city' => 'Cary',
        'state' => 'IL',
        'zip_code' => '60013',
        'belongs_to_vendor_id' => $ownerVendor->id,
    ]));

    $task = Task::withoutEvents(fn () => Task::create([
        'title' => 'Fix Electrical Outlet',
        'type' => 'task',
        'order' => 1,
        'project_id' => $project->id,
        'vendor_id' => $subjectVendor->id,
        'belongs_to_vendor_id' => $ownerVendor->id,
        'created_by_user_id' => 1,
        'vendor_status' => null,
    ]));

    $project->forceFill([
        'service_availability' => [
            'slots' => [
                ['date' => '2026-07-08', 'time' => 'Anytime'],
                ['date' => '2026-07-09', 'time' => '7-9 AM'],
                ['date' => '2026-07-13', 'time' => '3-5 PM'],
            ],
            'submitted_at' => now()->toIso8601String(),
            'task_ids' => [$task->id],
        ],
    ])->saveQuietly();

    $component = Livewire::test(AvailabilityIndex::class, ['token' => $subjectVendor->availability_token])
        ->call('openProposeDatesModal', $task->id);

    $slots = $component->instance()->proposedPreferredSlots();

    expect($slots)->toHaveCount(3)
        ->and($slots[0]['date'])->toBe('2026-07-08')
        ->and(collect($slots[1]['times'])->pluck('time')->all())->toBe(['7-9 AM']);

    $component->call('applyPreferredSlot', '2026-07-09', '7-9 AM')
        ->assertSet('proposedDates', ['2026-07-09'])
        ->assertSet('proposedTimeSettings.2026-07-09.use_time', true)
        ->assertSet('proposedTimeSettings.2026-07-09.start_time', '07:00')
        ->assertSet('proposedTimeSettings.2026-07-09.end_time', '09:00');
});

it('hides homeowner submitted times for a task not covered by the submission', function () {
    $ownerVendor = Vendor::factory()->create([
        'business_name' => 'GS Construction',
        'short_name' => 'GS',
        'options' => '{}',
    ]);

    $subjectVendor = Vendor::factory()->create([
        'business_name' => 'Smartech Electric',
        'short_name' => 'SE',
        'availability_token' => 'test-vendor-token-uncovered',
        'options' => '{}',
    ]);

    $client = Client::factory()->create();

    $project = Project::withoutEvents(fn () => Project::create([
        'project_name' => 'Test Project',
        'client_id' => $client->id,
        'address' => '239 Perth Rd',
        'city' => 'Cary',
        'state' => 'IL',
        'zip_code' => '60013',
        'belongs_to_vendor_id' => $ownerVendor->id,
    ]));

    $task = Task::withoutEvents(fn () => Task::create([
        'title' => 'Fix Cabinet',
        'type' => 'task',
        'order' => 1,
        'project_id' => $project->id,
        'vendor_id' => $subjectVendor->id,
        'belongs_to_vendor_id' => $ownerVendor->id,
        'created_by_user_id' => 1,
        'vendor_status' => null,
    ]));

    $project->forceFill([
        'service_availability' => [
            'slots' => [
                ['date' => '2026-07-08', 'time' => 'Anytime'],
            ],
            'submitted_at' => now()->toIso8601String(),
            'task_ids' => [$task->id + 999],
        ],
    ])->saveQuietly();

    $slots = Livewire::test(AvailabilityIndex::class, ['token' => $subjectVendor->availability_token])
        ->call('openProposeDatesModal', $task->id)
        ->instance()
        ->proposedPreferredSlots();

    expect($slots)->toBe([]);
});

it('groups upcoming scheduled tasks by date with date headers', function () {
    $ownerVendor = Vendor::factory()->create([
        'business_name' => 'GS Construction',
        'short_name' => 'GS',
        'options' => '{}',
    ]);

    $subjectVendor = Vendor::factory()->create([
        'business_name' => 'Smartech Electric',
        'short_name' => 'SE',
        'availability_token' => 'test-vendor-token-grouped',
        'options' => '{}',
    ]);

    $client = Client::factory()->create();

    $project = Project::withoutEvents(fn () => Project::create([
        'project_name' => 'Test Project',
        'client_id' => $client->id,
        'address' => '239 Perth Rd',
        'city' => 'Cary',
        'state' => 'IL',
        'zip_code' => '60013',
        'belongs_to_vendor_id' => $ownerVendor->id,
    ]));

    $dayOne = now()->addDays(2)->startOfDay();
    $dayTwo = now()->addDays(5)->startOfDay();

    $taskOne = Task::withoutEvents(fn () => Task::create([
        'title' => 'Rough-in Wiring',
        'type' => 'task',
        'order' => 1,
        'project_id' => $project->id,
        'vendor_id' => $subjectVendor->id,
        'belongs_to_vendor_id' => $ownerVendor->id,
        'created_by_user_id' => 1,
        'vendor_status' => Task::VENDOR_STATUS_CONFIRMED,
        'start_date' => $dayOne,
        'end_date' => $dayOne,
    ]));

    $taskTwo = Task::withoutEvents(fn () => Task::create([
        'title' => 'Panel Upgrade',
        'type' => 'task',
        'order' => 2,
        'project_id' => $project->id,
        'vendor_id' => $subjectVendor->id,
        'belongs_to_vendor_id' => $ownerVendor->id,
        'created_by_user_id' => 1,
        'vendor_status' => Task::VENDOR_STATUS_CONFIRMED,
        'start_date' => $dayTwo,
        'end_date' => $dayTwo,
    ]));

    Livewire::test(AvailabilityIndex::class, ['token' => $subjectVendor->availability_token])
        ->assertSee($dayOne->format('D, M j'))
        ->assertSee($dayTwo->format('D, M j'))
        ->assertSee('Rough-in Wiring')
        ->assertSee('Panel Upgrade')
        ->assertSee('1')
        ->assertSeeInOrder([
            $dayOne->format('D, M j'),
            'Rough-in Wiring',
            $dayTwo->format('D, M j'),
            'Panel Upgrade',
        ]);
});

it('lists a multi-date task under each of its scheduled dates', function () {
    $ownerVendor = Vendor::factory()->create([
        'business_name' => 'GS Construction',
        'short_name' => 'GS',
        'options' => '{}',
    ]);

    $subjectVendor = Vendor::factory()->create([
        'business_name' => 'Smartech Electric',
        'short_name' => 'SE',
        'availability_token' => 'test-vendor-token-multidate',
        'options' => '{}',
    ]);

    $client = Client::factory()->create();

    $project = Project::withoutEvents(fn () => Project::create([
        'project_name' => 'Test Project',
        'client_id' => $client->id,
        'address' => '239 Perth Rd',
        'city' => 'Cary',
        'state' => 'IL',
        'zip_code' => '60013',
        'belongs_to_vendor_id' => $ownerVendor->id,
    ]));

    $dayOne = now()->addDays(2)->startOfDay();
    $dayTwo = now()->addDays(4)->startOfDay();

    Task::withoutEvents(fn () => Task::create([
        'title' => 'Multi Day Job',
        'type' => 'task',
        'order' => 1,
        'project_id' => $project->id,
        'vendor_id' => $subjectVendor->id,
        'belongs_to_vendor_id' => $ownerVendor->id,
        'created_by_user_id' => 1,
        'vendor_status' => Task::VENDOR_STATUS_CONFIRMED,
        'start_date' => $dayOne,
        'end_date' => $dayTwo,
        'options' => (object) [
            'dates' => [$dayOne->format('Y-m-d'), $dayTwo->format('Y-m-d')],
        ],
    ]));

    Livewire::test(AvailabilityIndex::class, ['token' => $subjectVendor->availability_token])
        ->assertSee($dayOne->format('D, M j'))
        ->assertSee($dayTwo->format('D, M j'))
        ->assertSeeInOrder([
            $dayOne->format('D, M j'),
            'Multi Day Job',
            $dayTwo->format('D, M j'),
            'Multi Day Job',
        ]);
});

it('shows the day position for multi-date tasks', function () {
    $ownerVendor = Vendor::factory()->create([
        'business_name' => 'GS Construction',
        'short_name' => 'GS',
        'options' => '{}',
    ]);

    $subjectVendor = Vendor::factory()->create([
        'business_name' => 'Smartech Electric',
        'short_name' => 'SE',
        'availability_token' => 'test-vendor-token-day-counter',
        'options' => '{}',
    ]);

    $client = Client::factory()->create();

    $project = Project::withoutEvents(fn () => Project::create([
        'project_name' => 'Test Project',
        'client_id' => $client->id,
        'address' => '239 Perth Rd',
        'city' => 'Cary',
        'state' => 'IL',
        'zip_code' => '60013',
        'belongs_to_vendor_id' => $ownerVendor->id,
    ]));

    $dayOne = now()->addDays(2)->startOfDay();
    $dayTwo = now()->addDays(3)->startOfDay();
    $dayThree = now()->addDays(4)->startOfDay();

    Task::withoutEvents(fn () => Task::create([
        'title' => 'Three Day Job',
        'type' => 'task',
        'order' => 1,
        'project_id' => $project->id,
        'vendor_id' => $subjectVendor->id,
        'belongs_to_vendor_id' => $ownerVendor->id,
        'created_by_user_id' => 1,
        'vendor_status' => Task::VENDOR_STATUS_CONFIRMED,
        'start_date' => $dayOne,
        'end_date' => $dayThree,
        'options' => (object) [
            'dates' => [
                $dayOne->format('Y-m-d'),
                $dayTwo->format('Y-m-d'),
                $dayThree->format('Y-m-d'),
            ],
        ],
    ]));

    $html = Livewire::test(AvailabilityIndex::class, ['token' => $subjectVendor->availability_token])->html();

    expect(substr_count($html, '1/3'))->toBe(1)
        ->and(substr_count($html, '2/3'))->toBe(1)
        ->and(substr_count($html, '3/3'))->toBe(1);
});

it('renders action footer only once for a multi-date task', function () {
    $ownerVendor = Vendor::factory()->create([
        'business_name' => 'GS Construction',
        'short_name' => 'GS',
        'options' => '{}',
    ]);

    $subjectVendor = Vendor::factory()->create([
        'business_name' => 'Smartech Electric',
        'short_name' => 'SE',
        'availability_token' => 'test-vendor-token-single-footer',
        'options' => '{}',
    ]);

    $client = Client::factory()->create();

    $project = Project::withoutEvents(fn () => Project::create([
        'project_name' => 'Test Project',
        'client_id' => $client->id,
        'address' => '239 Perth Rd',
        'city' => 'Cary',
        'state' => 'IL',
        'zip_code' => '60013',
        'belongs_to_vendor_id' => $ownerVendor->id,
    ]));

    $dayOne = now()->addDays(2)->startOfDay();
    $dayTwo = now()->addDays(4)->startOfDay();

    Task::withoutEvents(fn () => Task::create([
        'title' => 'Multi Day Job',
        'type' => 'task',
        'order' => 1,
        'project_id' => $project->id,
        'vendor_id' => $subjectVendor->id,
        'belongs_to_vendor_id' => $ownerVendor->id,
        'created_by_user_id' => 1,
        'vendor_status' => Task::VENDOR_STATUS_REQUESTED,
        'start_date' => $dayOne,
        'end_date' => $dayTwo,
        'options' => (object) [
            'dates' => [$dayOne->format('Y-m-d'), $dayTwo->format('Y-m-d')],
        ],
    ]));

    $component = Livewire::test(AvailabilityIndex::class, ['token' => $subjectVendor->availability_token]);
    $html = $component->html();

    expect(substr_count($html, 'Available'))->toBe(1)
        ->and(substr_count($html, 'Change'))->toBe(1)
        ->and(substr_count($html, 'Decline'))->toBe(1);
});

it('hides reminder tasks owned by the creating company on the vendor availability page', function () {
    $ownerVendor = Vendor::factory()->create([
        'business_name' => 'GS Construction',
        'short_name' => 'GS',
        'options' => '{}',
    ]);

    $subjectVendor = Vendor::factory()->create([
        'business_name' => 'RG Tile',
        'short_name' => 'RG',
        'availability_token' => 'test-vendor-token-reminder-hidden',
        'options' => '{}',
    ]);

    $client = Client::factory()->create();

    $project = Project::withoutEvents(fn () => Project::create([
        'project_name' => 'Test Project',
        'client_id' => $client->id,
        'address' => '239 Perth Rd',
        'city' => 'Cary',
        'state' => 'IL',
        'zip_code' => '60013',
        'belongs_to_vendor_id' => $ownerVendor->id,
    ]));

    $futureDate = now()->addDays(2)->startOfDay();

    Task::withoutEvents(fn () => Task::create([
        'title' => 'Owner Reminder',
        'type' => 'Reminder',
        'order' => 1,
        'project_id' => $project->id,
        'vendor_id' => $subjectVendor->id,
        'belongs_to_vendor_id' => $ownerVendor->id,
        'created_by_user_id' => 1,
        'vendor_status' => Task::VENDOR_STATUS_REQUESTED,
        'start_date' => $futureDate,
        'end_date' => $futureDate,
    ]));

    Livewire::test(AvailabilityIndex::class, ['token' => $subjectVendor->availability_token])
        ->assertDontSee('Owner Reminder');
});

it('hides reminder tasks that are owned by the recipient vendor', function () {
    $ownerVendor = Vendor::factory()->create([
        'business_name' => 'GS Construction',
        'short_name' => 'GS',
        'options' => '{}',
    ]);

    $subjectVendor = Vendor::factory()->create([
        'business_name' => 'RG Tile',
        'short_name' => 'RG',
        'availability_token' => 'test-vendor-token-reminder-owned',
        'options' => '{}',
    ]);

    $client = Client::factory()->create();

    $project = Project::withoutEvents(fn () => Project::create([
        'project_name' => 'Test Project',
        'client_id' => $client->id,
        'address' => '239 Perth Rd',
        'city' => 'Cary',
        'state' => 'IL',
        'zip_code' => '60013',
        'belongs_to_vendor_id' => $ownerVendor->id,
    ]));

    $futureDate = now()->addDays(2)->startOfDay();

    Task::withoutEvents(fn () => Task::create([
        'title' => 'Vendor Reminder',
        'type' => 'Reminder',
        'order' => 1,
        'project_id' => $project->id,
        'vendor_id' => $subjectVendor->id,
        'belongs_to_vendor_id' => $subjectVendor->id,
        'created_by_user_id' => 1,
        'vendor_status' => Task::VENDOR_STATUS_REQUESTED,
        'start_date' => $futureDate,
        'end_date' => $futureDate,
    ]));

    Livewire::test(AvailabilityIndex::class, ['token' => $subjectVendor->availability_token])
        ->assertDontSee('Vendor Reminder');
});

it('shows a vendor-specific registration cta at the bottom for guests', function (): void {
    $subjectVendor = Vendor::factory()->create([
        'business_name' => 'RG Tile',
        'short_name' => 'RG',
        'availability_token' => 'test-vendor-token-cta',
        'options' => '{}',
    ]);

    Livewire::test(AvailabilityIndex::class, ['token' => $subjectVendor->availability_token])
        ->assertSee('Join Hive Contractors')
        ->assertSee('Register a Hive account to confirm availability, update arrival times, and stay connected with Hive Contractors.')
        ->assertSee('You’ll also be able to see project details, notifications, and schedule changes in one place.')
        ->assertSee('Register')
        ->assertSee('Login');
});

it('shows Waiting for homeowner, not Set Dates, on a service call the homeowner has not picked times for', function (int $statusCode) {
    $ownerVendor = Vendor::factory()->create(['business_name' => 'GS Construction', 'short_name' => 'GS', 'options' => '{}']);
    $subjectVendor = Vendor::factory()->create(['business_name' => 'RG Tile', 'short_name' => 'RT', 'availability_token' => 'test-vendor-token-waiting', 'options' => '{}']);
    $project = Project::withoutEvents(fn () => Project::create([
        'project_name' => 'Tile Repair Project', 'client_id' => Client::factory()->create()->id,
        'address' => '104 North Plum Grove Road', 'city' => 'Palatine', 'state' => 'IL', 'zip_code' => '60067',
        'belongs_to_vendor_id' => $ownerVendor->id,
    ]));
    \App\Models\ProjectStatus::withoutGlobalScopes()->where('project_id', $project->id)->delete();
    \App\Models\ProjectStatus::create(['project_id' => $project->id, 'belongs_to_vendor_id' => $ownerVendor->id, 'status_code' => $statusCode, 'start_date' => now()]);
    $task = Task::withoutEvents(fn () => Task::create([
        'title' => 'Tile Repair', 'type' => 'task', 'order' => 1, 'project_id' => $project->id,
        'vendor_id' => $subjectVendor->id, 'belongs_to_vendor_id' => $ownerVendor->id, 'created_by_user_id' => 1, 'vendor_status' => null,
    ]));

    Livewire::test(AvailabilityIndex::class, ['token' => $subjectVendor->availability_token])
        ->assertSee('Tile Repair')
        ->assertSee('Waiting for homeowner')
        ->assertDontSee('Set Dates / Schedule')
        ->call('openProposeDatesModal', $task->id)
        ->assertSet('proposingTaskId', null);

    // Once the homeowner submits times that cover the task, the sub can schedule it.
    $project->forceFill(['service_availability' => [
        'slots' => [['date' => now()->addDays(3)->toDateString(), 'time' => 'Anytime']],
        'submitted_at' => now()->toIso8601String(),
        'task_ids' => [$task->id],
    ]])->saveQuietly();

    Livewire::test(AvailabilityIndex::class, ['token' => $subjectVendor->availability_token])
        ->assertSee('Set Dates / Schedule')
        ->assertDontSee('Waiting for homeowner')
        ->call('openProposeDatesModal', $task->id)
        ->assertSet('proposingTaskId', $task->id);
})->with([
    'a Service Call project' => [8],
    'leftover work on a Complete project (RG Tile\'s tile repair)' => [7],
]);
