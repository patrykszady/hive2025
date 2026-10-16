<?php

use App\Livewire\Planner\CardsIndex;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * Dragging a scheduled card to another day in the Week view
 * (CardsIndex::moveTaskDay), and the Board listing only the projects with
 * work each day beside a "To schedule" column. activeProjects() needs MySQL's
 * JSON_OVERLAPS, so the Board is checked through a stub handing back known
 * projects (as PlannerWeekViewTest does).
 */
beforeEach(function () {
    config(['app.fake_browser_date' => '2026-10-01']); // a Thursday
    Queue::fake();
    \Flux\Flux::shouldReceive('toast')->andReturnNull();
});

function move_plannerUser(): User
{
    $vendor = Vendor::factory()->create(['business_type' => 'GC']);

    $user = User::query()->create([
        'first_name' => 'Move', 'last_name' => 'Planner',
        'email' => 'move-planner-'.uniqid().'@example.test',
        'cell_phone' => '224'.rand(1000000, 9999999),
        'primary_vendor_id' => $vendor->id,
    ]);
    $user->vendors()->attach($vendor->id, ['role_id' => 1]);

    return $user;
}

function move_plannerProject(User $user, string $address): Project
{
    return Project::factory()->create([
        'address' => $address,
        'project_name' => $address,
        'belongs_to_vendor_id' => $user->vendor->id,
        'client_id' => Client::factory()->create()->id,
    ]);
}

/** A CardsIndex whose activeProjects() is the given projects, tasks loaded. */
function move_plannerWith(iterable $projects): CardsIndex
{
    $component = new class extends CardsIndex
    {
        public $stubProjects;

        #[\Livewire\Attributes\Computed]
        public function activeProjects()
        {
            return $this->stubProjects;
        }
    };
    $component->stubProjects = collect($projects)->each(fn (Project $project) => $project->load('tasks'));

    return $component;
}

it('moves the one day of a task that was dragged, and its arrival time with it', function () {
    $user = move_plannerUser();
    $this->actingAs($user);

    $task = Task::factory()->create([
        'project_id' => move_plannerProject($user, '949 Elm')->id,
        'title' => 'Drywall',
        'start_date' => '2026-10-01',
        'end_date' => '2026-10-05',
        'options' => [
            'dates' => ['2026-10-01', '2026-10-02', '2026-10-05'],
            'time_settings' => ['2026-10-02' => ['use_time' => true, 'start_time' => '09:00', 'end_time' => '12:00']],
        ],
    ]);

    (new CardsIndex)->moveTaskDay($task->id, '2026-10-02', '2026-10-06');

    $task->refresh();
    $options = json_decode(json_encode($task->options), true);

    expect($options['dates'])->toBe(['2026-10-01', '2026-10-05', '2026-10-06'])
        ->and($task->start_date->format('Y-m-d'))->toBe('2026-10-01')
        ->and($task->end_date->format('Y-m-d'))->toBe('2026-10-06')
        ->and($options['time_settings'])->toHaveKey('2026-10-06')
        ->and($options['time_settings'])->not->toHaveKey('2026-10-02')
        ->and($options['time_settings']['2026-10-06']['start_time'])->toBe('09:00');
});

it('leaves a task alone when dropped on a day it already has', function () {
    $user = move_plannerUser();
    $this->actingAs($user);

    $task = Task::factory()->create([
        'project_id' => move_plannerProject($user, '949 Elm')->id,
        'start_date' => '2026-10-01',
        'end_date' => '2026-10-02',
        'options' => ['dates' => ['2026-10-01', '2026-10-02']],
    ]);

    (new CardsIndex)->moveTaskDay($task->id, '2026-10-01', '2026-10-02');

    expect(json_decode(json_encode($task->fresh()->options), true)['dates'])->toBe(['2026-10-01', '2026-10-02']);
});

it('shifts a plain start-to-end task whole, keeping it a range', function () {
    $user = move_plannerUser();
    $this->actingAs($user);

    $task = Task::factory()->create([
        'project_id' => move_plannerProject($user, '215 Huron')->id,
        'title' => 'Demo',
        'start_date' => '2026-10-05',
        'end_date' => '2026-10-07',
        'options' => [],
    ]);

    (new CardsIndex)->moveTaskDay($task->id, '2026-10-06', '2026-10-08');

    $task->refresh();

    expect($task->start_date->format('Y-m-d'))->toBe('2026-10-07')
        ->and($task->end_date->format('Y-m-d'))->toBe('2026-10-09')
        ->and(json_decode(json_encode($task->options), true)['dates'] ?? [])->toBe([]);
});

it('never moves another company\'s task, nor a malformed day', function () {
    $owner = move_plannerUser();
    $this->actingAs($owner);
    $task = Task::factory()->create([
        'project_id' => move_plannerProject($owner, '949 Elm')->id,
        'start_date' => '2026-10-01',
        'end_date' => '2026-10-01',
        'options' => ['dates' => ['2026-10-01']],
    ]);

    $this->actingAs(move_plannerUser());
    (new CardsIndex)->moveTaskDay($task->id, '2026-10-01', '2026-10-03');

    $this->actingAs($owner);
    (new CardsIndex)->moveTaskDay($task->id, '2026-10-01', 'next-friday');

    expect(json_decode(json_encode($task->fresh()->options), true)['dates'])->toBe(['2026-10-01']);
});

it('lists only the projects with work each day on the Board, with a count, and the rest in To schedule', function () {
    $user = move_plannerUser();
    $this->actingAs($user);

    $elm = move_plannerProject($user, '949 Elm');
    $pond = move_plannerProject($user, '348 Pondview');
    Task::factory()->create(['project_id' => $elm->id, 'title' => 'Tiles', 'start_date' => '2026-10-01', 'end_date' => '2026-10-01', 'options' => ['dates' => ['2026-10-01']]]);
    Task::factory()->create(['project_id' => $elm->id, 'title' => 'Grout', 'start_date' => '2026-10-01', 'end_date' => '2026-10-01', 'options' => ['dates' => ['2026-10-01']]]);
    Task::factory()->create(['project_id' => $pond->id, 'title' => 'Painting Touchups', 'options' => []]);

    $planner = move_plannerWith([$elm, $pond]);
    $days = $planner->kanbanColumns()->keyBy(fn ($day) => $day->day->format('Y-m-d'));

    expect($days['2026-10-01']->columns->pluck('title')->all())->toBe(['949 Elm'])
        ->and($days['2026-10-01']->taskCount)->toBe(2)
        ->and($days['2026-10-02']->columns)->toHaveCount(0)
        ->and($days['2026-10-02']->taskCount)->toBe(0)
        ->and($planner->boardBacklog()->map(fn ($row) => [$row->project->address, $row->undated])->all())->toBe([['348 Pondview', 1]]);
});
