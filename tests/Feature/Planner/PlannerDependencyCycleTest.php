<?php

use App\Livewire\Planner\CardsIndex;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskDependency;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Dependency cycles must never get in, and must never take the planner down
 * if one already has. The task modal's check compared an int against the
 * string wire:model hands it, so it let every cycle through, and the
 * critical-path recursion had no guard.
 */
it('follows the longest chain through a dependency graph', function (): void {
    $ids = CardsIndex::criticalPathFor([1 => 2, 2 => 3, 3 => 1, 4 => 1], [[1, 2, 0], [2, 3, 0]]);
    sort($ids);

    expect($ids)->toBe([1, 2, 3]);
});

it('terminates when the dependency graph contains a cycle', function (): void {
    $ids = CardsIndex::criticalPathFor([1 => 2, 2 => 2, 3 => 5], [[1, 2, 0], [2, 1, 0]]);

    expect($ids)->toBeArray()->not->toBeEmpty()
        ->and(array_diff($ids, [1, 2, 3]))->toBe([]);
});

it('ignores dependencies to tasks outside the graph', function (): void {
    expect(CardsIndex::criticalPathFor([1 => 2], [[9, 1, 0]]))->toBe([1]);
});

it('detects a circular dependency even when the ids arrive as strings', function (): void {
    $vendor = Vendor::factory()->create();
    $user = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $this->actingAs($user);

    $project = Project::factory()->create([
        'belongs_to_vendor_id' => $vendor->id,
        'client_id' => Client::factory()->create()->id,
    ]);
    $first = Task::factory()->create(['project_id' => $project->id]);
    $second = Task::factory()->create(['project_id' => $project->id]);

    TaskDependency::create([
        'predecessor_task_id' => $first->id,
        'successor_task_id' => $second->id,
        'type' => 'finish_to_start',
        'lag_days' => 0,
    ]);

    expect(TaskDependency::wouldCreateCircularDependency((string) $second->id, (string) $first->id))->toBeTrue()
        ->and(TaskDependency::wouldCreateCircularDependency((string) $first->id, (string) $second->id))->toBeFalse();
});
