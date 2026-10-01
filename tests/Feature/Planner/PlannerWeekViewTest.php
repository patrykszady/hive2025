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
 * The Week view (default since 2026-10-01): this week and next, day by day,
 * with the "To schedule" tray beside it. activeProjects() needs MySQL's
 * JSON_OVERLAPS, so the agenda and tray are checked through a stub that
 * hands back known projects (the GapTaskDatesTest pattern); scheduleTask()
 * runs for real against the sqlite test DB.
 */
beforeEach(function () {
    config(['app.fake_browser_date' => '2026-10-01']); // a Thursday
    Queue::fake();
    \Flux\Flux::shouldReceive('toast')->andReturnNull();
});

function week_plannerUser(): User
{
    $vendor = Vendor::factory()->create(['business_type' => 'GC']);

    $user = User::query()->create([
        'first_name' => 'Week', 'last_name' => 'Planner',
        'email' => 'week-planner-'.uniqid().'@example.test',
        'cell_phone' => '224'.rand(1000000, 9999999),
        'primary_vendor_id' => $vendor->id,
    ]);
    $user->vendors()->attach($vendor->id, ['role_id' => 1]);

    return $user;
}

function week_plannerProject(User $user, string $address): Project
{
    return Project::factory()->create([
        'address' => $address,
        'project_name' => $address,
        'belongs_to_vendor_id' => $user->vendor->id,
        'client_id' => Client::factory()->create()->id,
    ]);
}

/** A CardsIndex whose activeProjects() is the given projects, tasks loaded. */
function week_plannerWith(iterable $projects): CardsIndex
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

it('opens on the Week view, still under ?view=', function () {
    expect((new CardsIndex())->viewMode)->toBe('week');

    $attrs = (new ReflectionClass(CardsIndex::class))->getProperty('viewMode')->getAttributes(\Livewire\Attributes\Url::class);
    expect($attrs[0]->getArguments())->toMatchArray(['as' => 'view']);
});

it('starts on this week\'s Monday and steps a week at a time', function () {
    $component = new CardsIndex();

    expect($component->agendaStartDate()->format('Y-m-d'))->toBe('2026-09-28');

    $component->agendaNextWeek();
    expect($component->agendaStart)->toBe('2026-10-05');

    $component->agendaPreviousWeek();
    $component->agendaPreviousWeek();
    expect($component->agendaStart)->toBe('2026-09-21');

    $component->agendaThisWeek();
    expect($component->agendaStart)->toBe('')
        ->and($component->agendaStartDate()->format('Y-m-d'))->toBe('2026-09-28');
});

it('snaps a hand-edited ?from= to its Monday and ignores a broken one', function () {
    $component = new CardsIndex();

    $component->agendaStart = '2026-10-08';
    expect($component->agendaStartDate()->format('Y-m-d'))->toBe('2026-10-05');

    $component->agendaStart = 'next-tuesday';
    expect($component->agendaStartDate()->format('Y-m-d'))->toBe('2026-09-28');
});

it('lays out two weeks, each task on every day it is booked, grouped by project', function () {
    $user = week_plannerUser();
    $this->actingAs($user);

    $elm = week_plannerProject($user, '949 Elm');
    $huron = week_plannerProject($user, '215 Huron');
    Task::factory()->create(['project_id' => $elm->id, 'title' => 'Tiles', 'options' => ['dates' => ['2026-09-29', '2026-10-01']], 'start_date' => '2026-09-29', 'end_date' => '2026-10-01']);
    Task::factory()->create(['project_id' => $elm->id, 'title' => 'Grout', 'options' => ['dates' => ['2026-10-01']], 'start_date' => '2026-10-01', 'end_date' => '2026-10-01']);
    Task::factory()->create(['project_id' => $huron->id, 'title' => 'Demo', 'options' => [], 'start_date' => '2026-10-05', 'end_date' => '2026-10-07']);
    Task::factory()->create(['project_id' => $huron->id, 'title' => 'Painting', 'options' => []]);

    $days = week_plannerWith([$elm, $huron])->agendaDays()->keyBy('date');

    $titles = fn (string $date) => $days[$date]['groups']->mapWithKeys(fn (array $group) => [
        $group['project']->address => $group['tasks']->pluck('title')->all(),
    ])->all();

    expect($days->keys()->first())->toBe('2026-09-28')
        ->and($days->keys()->last())->toBe('2026-10-11')
        ->and($days)->toHaveCount(14)
        ->and($days['2026-10-01']['isToday'])->toBeTrue()
        ->and($days['2026-09-29']['isPast'])->toBeTrue()
        ->and($titles('2026-09-29'))->toBe(['949 Elm' => ['Tiles']])
        ->and($titles('2026-09-30'))->toBe([])
        ->and($titles('2026-10-01'))->toBe(['949 Elm' => ['Grout', 'Tiles']])
        ->and($titles('2026-10-06'))->toBe(['215 Huron' => ['Demo']])
        ->and($days->flatMap(fn (array $day) => $day['groups']->flatMap(fn (array $group) => $group['tasks']->pluck('title')))->contains('Painting'))->toBeFalse();
});

it('fills the To schedule tray with undated, live tasks, grouped by project', function () {
    $user = week_plannerUser();
    $this->actingAs($user);

    $elm = week_plannerProject($user, '949 Elm');
    $pond = week_plannerProject($user, '348 Pondview');
    Task::factory()->create(['project_id' => $elm->id, 'title' => 'HVAC', 'options' => []]);
    Task::factory()->create(['project_id' => $elm->id, 'title' => 'Booked', 'options' => ['dates' => ['2026-10-02']], 'start_date' => '2026-10-02', 'end_date' => '2026-10-02']);
    Task::factory()->create(['project_id' => $pond->id, 'title' => 'Painting', 'options' => []]);
    Task::factory()->create(['project_id' => $pond->id, 'title' => 'Gone', 'options' => []])->delete();

    $tray = week_plannerWith([$elm, $pond])->toScheduleByProject()->mapWithKeys(fn (array $group) => [
        $group['project']->address => $group['tasks']->pluck('title')->all(),
    ])->all();

    expect($tray)->toBe(['949 Elm' => ['HVAC'], '348 Pondview' => ['Painting']]);
});

it('books an unscheduled task on the day it is dropped on', function () {
    $user = week_plannerUser();
    $this->actingAs($user);

    $project = week_plannerProject($user, '949 Elm');
    $task = Task::factory()->create(['project_id' => $project->id, 'title' => 'Plumbing', 'options' => ['saturday' => false]]);

    (new CardsIndex())->scheduleTask($task->id, '2026-10-06');

    $task->refresh();
    expect($task->start_date->format('Y-m-d'))->toBe('2026-10-06')
        ->and($task->end_date->format('Y-m-d'))->toBe('2026-10-06')
        ->and($task->options->dates)->toBe(['2026-10-06'])
        ->and($task->options->saturday)->toBeFalse();
});

it('leaves a task that already has dates, and a malformed date, alone', function () {
    $user = week_plannerUser();
    $this->actingAs($user);

    $project = week_plannerProject($user, '949 Elm');
    $booked = Task::factory()->create(['project_id' => $project->id, 'title' => 'Booked', 'options' => ['dates' => ['2026-10-02']], 'start_date' => '2026-10-02', 'end_date' => '2026-10-02']);
    $open = Task::factory()->create(['project_id' => $project->id, 'title' => 'Open', 'options' => []]);

    $component = new CardsIndex();
    $component->scheduleTask($booked->id, '2026-10-09');
    $component->scheduleTask($open->id, '2026-13-40');
    $component->scheduleTask($open->id, '10/06/2026');

    expect($booked->fresh()->start_date->format('Y-m-d'))->toBe('2026-10-02')
        ->and($open->fresh()->start_date)->toBeNull()
        ->and($open->fresh()->options->dates ?? null)->toBeNull();
});

it('never books another company\'s task', function () {
    $mine = week_plannerUser();
    $theirs = week_plannerUser();

    // ProjectObserver stamps the signed-in user's company on a new project.
    $this->actingAs($theirs);
    $theirTask = Task::factory()->create([
        'project_id' => week_plannerProject($theirs, '1 Elsewhere Ln')->id,
        'title' => 'Not yours',
        'options' => [],
    ]);

    $this->actingAs($mine);
    (new CardsIndex())->scheduleTask($theirTask->id, '2026-10-06');

    expect($theirTask->fresh()->start_date)->toBeNull();
});

it('treats Mine as a filter that Clear filters resets', function () {
    $component = new CardsIndex();
    expect($component->hasActiveFilters())->toBeFalse();

    $component->onlyMine = true;
    expect($component->hasActiveFilters())->toBeTrue();

    $component->clearFilters();
    expect($component->onlyMine)->toBeFalse();

    $attrs = (new ReflectionClass(CardsIndex::class))->getProperty('onlyMine')->getAttributes(\Livewire\Attributes\Url::class);
    expect($attrs[0]->getArguments())->toMatchArray(['as' => 'mine']);
});
