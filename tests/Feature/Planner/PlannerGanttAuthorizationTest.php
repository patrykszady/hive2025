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
 * Drag and link calls arrive with bare task ids, and Task has no tenant
 * scope of its own. Only tasks on projects the signed-in vendor can see
 * (ProjectScope) may be moved or linked.
 *
 * @return array{0: User, 1: Task}
 */
function vendorWithTask(): array
{
    $vendor = Vendor::factory()->create();
    $user = User::factory()->create(['primary_vendor_id' => $vendor->id]);

    test()->actingAs($user);

    $project = Project::factory()->create([
        'belongs_to_vendor_id' => $vendor->id,
        'client_id' => Client::factory()->create()->id,
    ]);
    $task = Task::factory()->create([
        'project_id' => $project->id,
        'start_date' => '2026-09-21',
        'end_date' => '2026-09-23',
        'options' => ['dates' => ['2026-09-21', '2026-09-22', '2026-09-23']],
    ]);

    return [$user, $task];
}

function visibleTaskFor(int $taskId): ?Task
{
    $method = new ReflectionMethod(CardsIndex::class, 'visibleTask');

    return $method->invoke(new CardsIndex(), $taskId);
}

it('sees its own tenant\'s task but not another vendor\'s', function (): void {
    [, $otherTask] = vendorWithTask();
    [$user, $ownTask] = vendorWithTask();

    $this->actingAs($user);

    expect(visibleTaskFor($ownTask->id)?->id)->toBe($ownTask->id)
        ->and(visibleTaskFor($otherTask->id))->toBeNull()
        ->and(visibleTaskFor(999999))->toBeNull();
});

it('refuses to move another vendor\'s task', function (): void {
    [, $otherTask] = vendorWithTask();
    [$user] = vendorWithTask();

    $this->actingAs($user);

    (new CardsIndex())->updateTaskDates($otherTask->id, '2026-09-28', '2026-09-30', '2026-09-21', '2026-09-23');

    expect($otherTask->fresh()->start_date->toDateString())->toBe('2026-09-21')
        ->and($otherTask->fresh()->end_date->toDateString())->toBe('2026-09-23');
});

it('refuses to link another vendor\'s tasks', function (): void {
    [, $otherTask] = vendorWithTask();
    [$user, $ownTask] = vendorWithTask();

    $this->actingAs($user);

    (new CardsIndex())->createDependencyLink($otherTask->id, 'finish', $ownTask->id, 'start');

    expect(\App\Models\TaskDependency::count())->toBe(0);
});
