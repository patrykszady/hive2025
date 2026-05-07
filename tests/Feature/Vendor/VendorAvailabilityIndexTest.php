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
