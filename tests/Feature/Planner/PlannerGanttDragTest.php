<?php
use App\Livewire\Planner\CardsIndex;
use App\Models\{Task, Project, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('persists drag updates to task dates and reflects them in gantt rows', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create();
    $task = Task::factory()->create([
        'project_id' => $project->id,
        'start_date' => now()->startOfDay()->format('Y-m-d'),
        'end_date'   => now()->startOfDay()->addDays(2)->format('Y-m-d'),
    ]);

    $newStart = now()->startOfDay()->addDays(5)->format('Y-m-d');
    $newEnd   = now()->startOfDay()->addDays(7)->format('Y-m-d');

    $comp = Livewire::actingAs($user)
        ->test(CardsIndex::class)
        ->set('viewMode', 'gantt')
        ->call('updateTaskDates', $task->id, $newStart, $newEnd);

    expect($task->fresh()->start_date->format('Y-m-d'))->toBe($newStart)
        ->and($task->fresh()->end_date->format('Y-m-d'))->toBe($newEnd);
});
