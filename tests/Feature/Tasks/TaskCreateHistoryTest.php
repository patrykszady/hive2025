<?php

use App\Livewire\Tasks\TaskCreate;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * The task editor's history timeline prints every logged change. Some
 * writers (ScheduledVisitApplier, planner reschedules) log `dates` and
 * `time_settings` as attributes of their own — nested per-day arrays — and
 * on 2026-10-29 opening such a task from the Send Schedule modal died with
 * "htmlspecialchars(): Argument #1 must be of type string, array given".
 */
/** @return array{0: Task, 1: User} the task and an operator of its vendor who may open it */
function historyWorld(): array
{
    $vendor = Vendor::factory()->create(['timezone' => 'America/Chicago']);
    $operator = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $vendor->users()->attach($operator->id, ['role_id' => 1, 'is_employed' => 1]);
    $client = Client::factory()->create();

    $project = Project::withoutEvents(fn () => Project::create([
        'project_name' => '1 Nancy Ln',
        'client_id' => $client->id,
        'address' => '1 Nancy Ln',
        'city' => 'Northbrook',
        'state' => 'IL',
        'zip_code' => '60062',
        'belongs_to_vendor_id' => $vendor->id,
    ]));
    $project->vendors()->attach($vendor->id, ['client_id' => $client->id]);

    $task = Task::withoutEvents(fn () => Task::create([
        'title' => 'Tile Repair',
        'type' => 'Task',
        'order' => 1,
        'project_id' => $project->id,
        'user_ids' => [(string) $operator->id],
        'belongs_to_vendor_id' => $vendor->id,
        'created_by_user_id' => $operator->id,
        'start_date' => '2026-10-29',
        'end_date' => '2026-10-29',
        'options' => ['dates' => ['2026-10-29'], 'time_settings' => ['2026-10-29' => ['use_time' => false]]],
    ]));

    return [$task, $operator];
}

it('opens a task whose history logged per-day time settings as a top-level attribute', function (): void {
    [$task, $operator] = historyWorld();

    activity()->performedOn($task)->event('updated')->withProperties([
        'attributes' => ['dates' => ['2026-10-29'], 'end_date' => '2026-10-29', 'start_date' => '2026-10-29', 'time_settings' => ['2026-10-29' => ['use_time' => false]]],
        'old' => ['dates' => ['2026-10-29'], 'end_date' => '2026-10-29', 'start_date' => '2026-10-29', 'time_settings' => ['2026-10-29' => ['use_time' => false]]],
    ])->log('updated');

    Livewire::actingAs($operator)
        ->test(TaskCreate::class)
        ->call('editTask', $task->id)
        ->assertHasNoErrors()
        ->assertSee('Task updated')
        ->assertDontSee('{&quot;use_time&quot;', false)
        ->assertDontSee('2026-10-29: ');
});

it('shows a top-level date move as the day that left and the day that arrived', function (): void {
    [$task, $operator] = historyWorld();

    activity()->performedOn($task)->event('updated')->withProperties([
        'attributes' => ['dates' => ['2026-10-30'], 'start_date' => '2026-10-30', 'time_settings' => ['2026-10-30' => ['use_time' => true, 'start_time' => '09:00', 'end_time' => '10:00']]],
        'old' => ['dates' => ['2026-10-29'], 'start_date' => '2026-10-29', 'time_settings' => ['2026-10-29' => ['use_time' => false]]],
    ])->log('updated');

    Livewire::actingAs($operator)
        ->test(TaskCreate::class)
        ->call('editTask', $task->id)
        ->assertHasNoErrors()
        ->assertSee('Thu, Oct 29, 2026')
        ->assertSee('Fri, Oct 30, 2026 · 9AM–10AM');
});

it('prints any other logged array as lines instead of dying', function (): void {
    [$task, $operator] = historyWorld();

    activity()->performedOn($task)->event('updated')->withProperties([
        'attributes' => [
            'nylas_meet_event' => ['event_id' => 'evt_1', 'organizer_email' => 'greg@gs.construction'],
            'meeting_participants' => ['greg@gs.construction', 'nancy@example.com'],
        ],
        'old' => ['nylas_meet_event' => null, 'meeting_participants' => []],
    ])->log('updated');

    Livewire::actingAs($operator)
        ->test(TaskCreate::class)
        ->call('editTask', $task->id)
        ->assertHasNoErrors()
        ->assertSee('event_id: evt_1')
        ->assertSee('nancy@example.com');
});
