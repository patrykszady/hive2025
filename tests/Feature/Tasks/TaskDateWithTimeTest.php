<?php

use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('shows arrival time range for single-day tasks on date_with_time', function (): void {
    $vendor = Vendor::factory()->create();
    $client = Client::factory()->create();

    $user = User::query()->create([
        'first_name' => 'Owner',
        'last_name' => 'User',
        'email' => 'owner.task-date-with-time@example.com',
        'cell_phone' => '2245550499',
        'primary_vendor_id' => $vendor->id,
    ]);
    $vendor->users()->attach($user->id, ['is_employed' => true, 'role_id' => 1]);

    $this->actingAs($user);

    $project = Project::query()->create([
        'project_name' => 'Wallpaper Project',
        'client_id' => $client->id,
        'belongs_to_vendor_id' => $vendor->id,
        'address' => '2932 N 77th Ct',
        'city' => 'Elmwood Park',
        'state' => 'IL',
        'zip_code' => 60707,
    ]);

    $task = Task::query()->create([
        'title' => 'Wallpaper Repair',
        'project_id' => $project->id,
        'vendor_id' => $vendor->id,
        'type' => 'Task',
        'start_date' => '2026-06-29',
        'end_date' => '2026-06-29',
        'options' => [
            'time_settings' => [
                '2026-06-29' => [
                    'use_time' => true,
                    'start_time' => '13:00',
                    'end_time' => '14:00',
                ],
            ],
        ],
    ]);

    expect($task->date_with_time)->toBe('Mon, Jun 29, 2026 @ 1PM - 2PM');
});
