<?php

use App\Livewire\Planner\CardsIndex;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * /planner/cards?task={id} is the deep link a notification (or anything
 * else) uses to land on the planner with a specific task's edit modal
 * already open — see PlannerTaskNotificationLinkTest for the notification
 * side. CardsIndex::mount() dispatches 'editTask' to tasks.task-create the
 * same way its own wire:click rows do, so the modal opens exactly as it
 * would from a click.
 *
 * mount() is called directly on a bare instance rather than through
 * Livewire::test() — every existing CardsIndex test does the same (see
 * PlannerMoveAndBoardTest, PlannerGanttAuthorizationTest), because a real
 * Livewire render pulls in activeProjects(), whose JSON_OVERLAPS query MySQL
 * supports and the sqlite test database does not. dispatch() itself only
 * needs the object (Livewire tracks it by instance, not by a live request),
 * so mount()'s actual dispatch call is exercised for real; the override
 * below just records what went out so the test can read it back.
 */
/**
 * The event is captured as the actual object dispatch() returns, read back
 * only once the test is done calling mount() — ->to('tasks.task-create')
 * runs on that same returned object right after dispatch() hands it back,
 * so reading its 'component' property any earlier would still see null.
 */
function cardsIndexDispatchSpy(): CardsIndex
{
    return new class extends CardsIndex
    {
        /** @var list<array{event: string, params: array<string, mixed>, raw: \Livewire\Features\SupportEvents\Event}> */
        public array $recordedDispatches = [];

        public function dispatch($event, ...$params)
        {
            $dispatched = parent::dispatch($event, ...$params);

            $this->recordedDispatches[] = ['event' => $event, 'params' => $params, 'raw' => $dispatched];

            return $dispatched;
        }
    };
}

/** The component name an Event was ultimately ->to()'d at, read after the fact. */
function dispatchedEventTarget(\Livewire\Features\SupportEvents\Event $event): mixed
{
    return (new ReflectionProperty($event, 'component'))->getValue($event);
}

function deepLinkPlannerUser(): User
{
    $vendor = Vendor::factory()->create(['business_type' => 'GC']);

    $user = User::query()->create([
        'first_name' => 'Deep',
        'last_name' => 'Link',
        'email' => 'deep-link-'.uniqid().'@example.test',
        'cell_phone' => '224'.rand(1000000, 9999999),
        'primary_vendor_id' => $vendor->id,
    ]);
    $user->vendors()->attach($vendor->id, ['role_id' => 1]);

    return $user;
}

function deepLinkTask(User $user): Task
{
    $project = Project::factory()->create([
        'belongs_to_vendor_id' => $user->vendor->id,
        'client_id' => Client::factory()->create()->id,
    ]);

    return Task::withoutEvents(fn () => Task::create([
        'title' => 'Fix Siding',
        'type' => 'task',
        'order' => 0,
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $user->vendor->id,
        'created_by_user_id' => $user->id,
    ]));
}

it('opens the task edit modal when the planner loads with ?task=', function (): void {
    $user = deepLinkPlannerUser();
    $this->actingAs($user);
    $task = deepLinkTask($user);

    $planner = cardsIndexDispatchSpy();
    $planner->task = $task->id;
    $planner->mount();

    expect($planner->recordedDispatches)->toHaveCount(1)
        ->and($planner->recordedDispatches[0]['event'])->toBe('editTask')
        ->and($planner->recordedDispatches[0]['params'])->toBe(['task' => $task->id])
        ->and(dispatchedEventTarget($planner->recordedDispatches[0]['raw']))->toBe('tasks.task-create');
});

it('ignores an unknown task id — the planner just loads, no error', function (): void {
    $user = deepLinkPlannerUser();
    $this->actingAs($user);

    $planner = cardsIndexDispatchSpy();
    $planner->task = 999999;
    $planner->mount();

    expect($planner->recordedDispatches)->toBe([]);
});

it('ignores another vendor\'s task id — never leaks it hidden in an open modal', function (): void {
    $owner = deepLinkPlannerUser();
    $this->actingAs($owner);
    $otherTask = deepLinkTask($owner);

    $viewer = deepLinkPlannerUser();
    $this->actingAs($viewer);

    $planner = cardsIndexDispatchSpy();
    $planner->task = $otherTask->id;
    $planner->mount();

    expect($planner->recordedDispatches)->toBe([]);
});

it('does nothing when no task is given on the url', function (): void {
    $user = deepLinkPlannerUser();
    $this->actingAs($user);

    $planner = cardsIndexDispatchSpy();
    $planner->mount();

    expect($planner->recordedDispatches)->toBe([]);
});
