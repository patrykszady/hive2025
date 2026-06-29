<?php

use App\Livewire\Client\ScheduleIndex;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('pending tasks are collapsed by default on the client schedule page', function (): void {
    $client = Client::factory()->create();
    $vendor = Vendor::factory()->create();

    $project = Project::withoutEvents(fn () => Project::create([
        'project_name' => 'Client Schedule Project',
        'client_id' => $client->id,
        'belongs_to_vendor_id' => $vendor->id,
        'address' => '100 Main St',
        'city' => 'Cary',
        'state' => 'IL',
        'zip_code' => '60013',
    ]));

    $project->forceFill(['schedule_token' => 'test-client-schedule-token'])->saveQuietly();

    Task::withoutEvents(fn () => Task::create([
        'title' => 'Unscheduled Task',
        'type' => 'task',
        'order' => 1,
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $vendor->id,
        'created_by_user_id' => 1,
        'vendor_status' => Task::VENDOR_STATUS_REQUESTED,
    ]));

    $html = Livewire::test(ScheduleIndex::class, ['token' => $project->schedule_token])->html();

    expect($html)->toContain('Pending Tasks');
    expect($html)->not->toContain('<flux:accordion.item expanded');
});
