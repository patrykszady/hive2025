<?php

use App\Models\Project;
use App\Models\ProjectActionItem;
use App\Models\Task;
use App\Services\ActionItems\ScheduledVisitApplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Support/t3-fixtures.php';
require_once __DIR__.'/../../Support/service-call-fixtures.php';

/*
 * Patryk, 2026-11-06, project 381 Abbey: the reading pass made two items for
 * the same counter install — "Ultimate Stone Countertop Installation" (Kama's
 * email, owner Ultimate Stone) and "Countertop Install" (owner GS) — both
 * dated Nov 10. ScheduledVisitApplier turned the first into task #1234; the
 * no-duplicate guard (3ac727a1) correctly refused a second task for the
 * second, but it stayed OPEN beside it, for someone to dismiss by hand. Now
 * the other open items on the same project that mean the same visit fold
 * into the task this one became.
 */

function foldWorld(): array
{
    $world = serviceCallWorld('active');

    return $world + ['date' => now('America/Chicago')->addDays(5)->toDateString()];
}

function foldItem(Project $project, string $title, ?string $owner, ?string $dueOn, array $overrides = []): ProjectActionItem
{
    return ProjectActionItem::create(array_merge([
        'fingerprint' => ProjectActionItem::fingerprintFor($title),
        'project_id' => $project->id,
        'title' => $title,
        'owner' => $owner,
        'due_on' => $dueOn,
        'status' => ProjectActionItem::STATUS_OPEN,
        'first_mentioned_at' => now(),
        'last_mentioned_at' => now(),
    ], $overrides));
}

it('folds the GS-named twin into the task the Ultimate Stone item became, and leaves an unrelated to-do open', function () {
    $w = foldWorld();
    $project = $w['project'];

    $stone = foldItem($project, 'Ultimate Stone Countertop Installation', 'Ultimate Stone', $w['date']);
    $gs = foldItem($project, 'Countertop Install', 'GS', $w['date']);
    $shed = foldItem($project, 'Order the shed', 'GS', null);

    $task = app(ScheduledVisitApplier::class)->apply($stone, ['at' => Carbon::now(), 'source' => ['kind' => 'email', 'id' => 701]]);

    expect($task)->not->toBeNull()
        ->and($task->title)->toBe('Ultimate Stone Countertop Installation')
        ->and($stone->fresh()->status)->toBe(ProjectActionItem::STATUS_TASK)
        ->and($stone->fresh()->task_id)->toBe($task->id)
        ->and($gs->fresh()->status)->toBe(ProjectActionItem::STATUS_TASK)
        ->and($gs->fresh()->task_id)->toBe($task->id)
        ->and($shed->fresh()->status)->toBe(ProjectActionItem::STATUS_OPEN)
        ->and($shed->fresh()->task_id)->toBeNull();
});

it('never folds a same-title item on another project', function () {
    $w = foldWorld();
    $project = $w['project'];
    $elsewhere = sec3_makeProject($w['vendor'], $w['client'], ['project_name' => 'Garage', 'address' => '9 Other St']);

    $stone = foldItem($project, 'Ultimate Stone Countertop Installation', 'Ultimate Stone', $w['date']);
    $stranger = foldItem($elsewhere, 'Countertop Install', 'GS', $w['date']);

    $task = app(ScheduledVisitApplier::class)->apply($stone, ['at' => Carbon::now(), 'source' => ['kind' => 'email', 'id' => 702]]);

    expect($task)->not->toBeNull()
        ->and($stranger->fresh()->status)->toBe(ProjectActionItem::STATUS_OPEN)
        ->and($stranger->fresh()->task_id)->toBeNull();
});

it('folds the item into the team\'s own task when the no-duplicate guard refuses a second one', function () {
    $w = foldWorld();
    $project = $w['project'];
    $otherDay = now('America/Chicago')->addDays(20)->toDateString();

    $planned = Task::withoutEvents(fn () => Task::create([
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $w['vendor']->id,
        'created_by_user_id' => $w['admin']->id,
        'title' => 'Counter Measure',
        'type' => 'Task',
        'order' => 0,
        'start_date' => $otherDay,
        'end_date' => $otherDay,
        'options' => ['dates' => [$otherDay], 'time_settings' => []],
    ]));

    $item = foldItem($project, 'Counter Measure', 'Kama', $w['date']);
    $twin = foldItem($project, 'Ultimate Stone Counter Measurement', 'Ultimate Stone', $w['date']);

    $result = app(ScheduledVisitApplier::class)->apply($item, ['at' => Carbon::now(), 'source' => ['kind' => 'email', 'id' => 703]]);

    expect($result)->toBeNull()
        ->and($item->fresh()->status)->toBe(ProjectActionItem::STATUS_TASK)
        ->and($item->fresh()->task_id)->toBe($planned->id)
        ->and($twin->fresh()->status)->toBe(ProjectActionItem::STATUS_TASK)
        ->and($twin->fresh()->task_id)->toBe($planned->id)
        ->and(Task::query()->where('project_id', $project->id)->count())->toBe(1);
});

it('leaves an already done or dismissed twin alone', function () {
    $w = foldWorld();
    $project = $w['project'];

    $stone = foldItem($project, 'Ultimate Stone Countertop Installation', 'Ultimate Stone', $w['date']);
    $done = foldItem($project, 'Countertop Install', 'GS', $w['date'], ['status' => ProjectActionItem::STATUS_DONE, 'done_at' => now()]);
    $dismissed = foldItem($project, 'Countertop Install', 'GS', $w['date'], ['status' => ProjectActionItem::STATUS_DISMISSED]);

    app(ScheduledVisitApplier::class)->apply($stone, ['at' => Carbon::now(), 'source' => ['kind' => 'email', 'id' => 704]]);

    expect($done->fresh()->status)->toBe(ProjectActionItem::STATUS_DONE)
        ->and($done->fresh()->task_id)->toBeNull()
        ->and($dismissed->fresh()->status)->toBe(ProjectActionItem::STATUS_DISMISSED)
        ->and($dismissed->fresh()->task_id)->toBeNull();
});
