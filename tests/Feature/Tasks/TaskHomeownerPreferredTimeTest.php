<?php

use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @param  array<int, array{date: string, time: string}>  $slots
 */
function makeHomeownerPreferredProject(array $slots): Project
{
    $vendor = Vendor::factory()->create();
    $client = Client::factory()->create();

    $user = User::query()->create([
        'first_name' => 'Owner',
        'last_name' => 'User',
        'email' => 'owner.pref-icon-' . uniqid() . '@example.com',
        'cell_phone' => (string) random_int(2000000000, 9999999999),
        'primary_vendor_id' => $vendor->id,
    ]);
    $vendor->users()->attach($user->id, ['is_employed' => true, 'role_id' => 1]);

    test()->actingAs($user);

    return Project::query()->create([
        'project_name' => 'Preferred Project ' . uniqid(),
        'client_id' => $client->id,
        'belongs_to_vendor_id' => $vendor->id,
        'address' => '123 Main St',
        'city' => 'Chicago',
        'state' => 'IL',
        'zip_code' => 60601,
        'service_availability' => $slots === [] ? null : [
            'slots' => $slots,
            'submitted_at' => now()->toIso8601String(),
        ],
    ]);
}

/**
 * @param  array<string, mixed>  $options
 */
function makePreferredTask(Project $project, array $options): Task
{
    return Task::withoutEvents(fn () => Task::query()->create([
        'title' => 'Preferred Task',
        'project_id' => $project->id,
        'vendor_id' => $project->belongs_to_vendor_id,
        'belongs_to_vendor_id' => $project->belongs_to_vendor_id,
        'created_by_user_id' => auth()->id(),
        'type' => 'Task',
        'order' => 0,
        'options' => $options,
    ]));
}

it('reports no homeowner preferred times when the project has none', function (): void {
    $project = makeHomeownerPreferredProject([]);
    $task = makePreferredTask($project, ['dates' => ['2026-07-16']]);

    expect($task->projectHasHomeownerPreferredTimes())->toBeFalse()
        ->and($task->hasScheduledHomeownerPreferredTime())->toBeFalse();
});

it('reports unscheduled when the task has not applied a preferred slot', function (): void {
    $project = makeHomeownerPreferredProject([
        ['date' => '2026-07-16', 'time' => '1-3 PM'],
    ]);
    $task = makePreferredTask($project, ['dates' => []]);

    expect($task->projectHasHomeownerPreferredTimes())->toBeTrue()
        ->and($task->hasScheduledHomeownerPreferredTime())->toBeFalse();
});

it('reports scheduled when a preferred time frame is applied to the task', function (): void {
    $project = makeHomeownerPreferredProject([
        ['date' => '2026-07-16', 'time' => '1-3 PM'],
    ]);
    $task = makePreferredTask($project, [
        'dates' => ['2026-07-16'],
        'time_settings' => [
            '2026-07-16' => ['use_time' => true, 'start_time' => '13:00', 'end_time' => '15:00'],
        ],
    ]);

    expect($task->hasScheduledHomeownerPreferredTime())->toBeTrue();
});

it('reports scheduled when an Anytime preference is applied as a bare date', function (): void {
    $project = makeHomeownerPreferredProject([
        ['date' => '2026-07-20', 'time' => 'Anytime'],
    ]);
    $task = makePreferredTask($project, [
        'dates' => ['2026-07-20'],
        'time_settings' => [
            '2026-07-20' => ['use_time' => false],
        ],
    ]);

    expect($task->hasScheduledHomeownerPreferredTime())->toBeTrue();
});

it('reports unscheduled when the task date matches but the arrival time does not', function (): void {
    $project = makeHomeownerPreferredProject([
        ['date' => '2026-07-16', 'time' => '1-3 PM'],
    ]);
    $task = makePreferredTask($project, [
        'dates' => ['2026-07-16'],
        'time_settings' => [
            '2026-07-16' => ['use_time' => true, 'start_time' => '07:00', 'end_time' => '09:00'],
        ],
    ]);

    expect($task->hasScheduledHomeownerPreferredTime())->toBeFalse();
});

it('returns a null preferred indicator when the project has no preferred times', function (): void {
    $project = makeHomeownerPreferredProject([]);
    $task = makePreferredTask($project, ['dates' => []]);

    expect($task->preferredTimeIndicator())->toBeNull();
});

it('returns a schedule preferred indicator for an unscheduled task with current preferred times', function (): void {
    $project = makeHomeownerPreferredProject([
        ['date' => '2026-07-16', 'time' => '1-3 PM'],
    ]);
    $task = makePreferredTask($project, [
        'dates' => [],
    ]);

    $project->forceFill([
        'service_availability' => [
            'slots' => [
                ['date' => '2026-07-16', 'time' => '1-3 PM'],
            ],
            'task_ids' => [$task->id],
            'submitted_at' => now()->toIso8601String(),
        ],
    ])->save();

    expect($task->fresh()->preferredTimeIndicator())->toBe('schedule');
});

it('returns a scheduled preferred indicator for a scheduled task not listed in submitted task ids', function (): void {
    $project = makeHomeownerPreferredProject([
        ['date' => '2026-07-16', 'time' => '1-3 PM'],
    ]);

    $task = makePreferredTask($project, ['dates' => ['2026-07-08']]);
    $task->forceFill(['start_date' => '2026-07-08', 'end_date' => '2026-07-08'])->saveQuietly();

    $project->forceFill([
        'service_availability' => [
            'slots' => [
                ['date' => '2026-07-16', 'time' => '1-3 PM'],
            ],
            'task_ids' => [$task->id + 999],
            'submitted_at' => now()->toIso8601String(),
        ],
    ])->save();

    expect($task->fresh()->preferredTimeIndicator())->toBe('scheduled');
});

it('returns an awaiting indicator for a task the homeowner has not chosen times for', function (): void {
    $project = makeHomeownerPreferredProject([
        ['date' => '2026-07-16', 'time' => '1-3 PM'],
    ]);

    $task = makePreferredTask($project, ['dates' => []]);

    $project->forceFill([
        'service_availability' => [
            'slots' => [
                ['date' => '2026-07-16', 'time' => '1-3 PM'],
            ],
            'task_ids' => [$task->id + 999],
            'submitted_at' => now()->toIso8601String(),
        ],
    ])->save();

    expect($task->fresh()->preferredTimeIndicator())->toBe('pending');
});

it('returns a scheduled preferred indicator when a preferred slot is applied', function (): void {
    $project = makeHomeownerPreferredProject([
        ['date' => '2026-07-16', 'time' => '1-3 PM'],
    ]);
    $task = makePreferredTask($project, [
        'dates' => ['2026-07-16'],
        'time_settings' => [
            '2026-07-16' => ['use_time' => true, 'start_time' => '13:00', 'end_time' => '15:00'],
        ],
    ]);

    expect($task->preferredTimeIndicator())->toBe('scheduled');
});

it('returns a scheduled preferred indicator for a scheduled task that matches no preferred slot', function (): void {
    $project = makeHomeownerPreferredProject([
        ['date' => '2026-07-16', 'time' => '1-3 PM'],
    ]);
    $task = makePreferredTask($project, [
        'dates' => ['2026-06-30'],
        'time_settings' => [
            '2026-06-30' => ['use_time' => true, 'start_time' => '13:00', 'end_time' => '15:00'],
        ],
    ]);
    $task->forceFill(['start_date' => '2026-06-30', 'end_date' => '2026-06-30'])->saveQuietly();

    expect($task->preferredTimeIndicator())->toBe('scheduled');
});
