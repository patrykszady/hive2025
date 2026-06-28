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
        ->assertSee($dayOne->format('D, M j, Y'))
        ->assertSee($dayTwo->format('D, M j, Y'))
        ->assertSee('Rough-in Wiring')
        ->assertSee('Panel Upgrade')
        ->assertSee('1')
        ->assertSeeInOrder([
            $dayOne->format('D, M j, Y'),
            'Rough-in Wiring',
            $dayTwo->format('D, M j, Y'),
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
        ->assertSee($dayOne->format('D, M j, Y'))
        ->assertSee($dayTwo->format('D, M j, Y'))
        ->assertSeeInOrder([
            $dayOne->format('D, M j, Y'),
            'Multi Day Job',
            $dayTwo->format('D, M j, Y'),
            'Multi Day Job',
        ]);
});

it('hides reminder tasks owned by another company from the vendor availability page', function () {
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

it('shows reminder tasks that are owned by the same recipient vendor', function () {
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
        'belongs_to_vendor_id' => $subjectVendor->id,
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
        ->assertSee('Vendor Reminder');
});
