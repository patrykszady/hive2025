<?php

use App\Livewire\Planner\CardsIndex;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\Vendor;

/**
 * An in-memory project with a client and tasks, as activeProjects() loads it.
 *
 * @param  array<int, array{0: string, 1: ?string}>  $tasks  [title, vendor business name]
 */
function searchableProject(string $address, string $clientName, string $projectName, array $tasks): Project
{
    $project = new Project(['address' => $address, 'project_name' => $projectName]);
    $project->setRelation('client', new Client(['business_name' => $clientName]));
    $project->setRelation('tasks', collect($tasks)->map(function (array $spec): Task {
        $task = new Task(['title' => $spec[0]]);
        $task->setRelation('vendor', $spec[1] ? new Vendor(['business_name' => $spec[1]]) : null);

        return $task;
    }));

    return $project;
}

/** @return list<string> "address: task, task" per project the search keeps */
function plannerSearch(string $search): array
{
    $planner = new CardsIndex;
    $planner->search = $search;

    $projects = collect([
        searchableProject('949 Elm St', 'Abbey', 'Kitchen', [['Drywall', 'Morenos Drywall'], ['Stair Demo', null]]),
        searchableProject('3395 Portshire Dr', 'Taraszka', 'Paint', [['Plumbing Leak Repair', 'Accomplished J Plumbing'], ['Paint Trim', 'TBD Pro Painting']]),
        searchableProject('1400 Kenilwood Ln', 'Bates', 'Home Remodel', [['Window Install', 'Stan Palupski Construction']]),
    ]);

    return (fn () => $this->applySearch($projects))->call($planner)
        ->map(fn (Project $project) => $project->address.': '.$project->tasks->pluck('title')->implode(', '))
        ->all();
}

it('keeps every project when nothing is searched', function () {
    expect(plannerSearch(''))->toHaveCount(3);
});

it('keeps a project whole when its address, client or project name matches', function () {
    expect(plannerSearch('elm'))->toBe(['949 Elm St: Drywall, Stair Demo'])
        ->and(plannerSearch('taraszka'))->toBe(['3395 Portshire Dr: Plumbing Leak Repair, Paint Trim'])
        ->and(plannerSearch('remodel'))->toBe(['1400 Kenilwood Ln: Window Install']);
});

it('keeps only the matching tasks when a task title or vendor matches', function () {
    expect(plannerSearch('plumbing'))->toBe(['3395 Portshire Dr: Plumbing Leak Repair'])
        ->and(plannerSearch('MORENOS'))->toBe(['949 Elm St: Drywall'])
        ->and(plannerSearch('trim'))->toBe(['3395 Portshire Dr: Paint Trim']);
});

it('drops every project when nothing matches', function () {
    expect(plannerSearch('roofing'))->toBe([]);
});

it('counts a search as a filter, and Clear all empties it', function () {
    $planner = new CardsIndex;
    $planner->search = 'elm';

    expect($planner->hasActiveFilters())->toBeTrue();

    $planner->clearFilters();

    expect($planner->search)->toBe('')
        ->and($planner->hasActiveFilters())->toBeFalse();
});
