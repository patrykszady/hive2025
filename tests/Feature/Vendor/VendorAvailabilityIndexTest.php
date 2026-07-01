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

    Livewire::test(AvailabilityIndex::class, ['token' => $subjectVendor->availability_token])
        ->assertSee('Pending Tasks')
        ->assertSee('Fix Electrical')
        ->assertSee('No Date');
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
