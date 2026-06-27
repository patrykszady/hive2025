<?php

use App\Livewire\Planner\CardsIndex;
use App\Models\Task;

it('exposes a taskList computed method for the list view', function (): void {
    $reflection = new ReflectionClass(CardsIndex::class);

    expect($reflection->hasMethod('taskList'))->toBeTrue();

    $attrs = $reflection->getMethod('taskList')
        ->getAttributes(\Livewire\Attributes\Computed::class);

    expect($attrs)->not->toBeEmpty('taskList should be annotated with #[Computed]');
});

it('resolves planner dates from the options.dates array', function (): void {
    $task = new Task();
    $task->options = ['dates' => ['2026-01-12', '2026-01-10', '2026-01-11']];

    $dates = invokeTaskPlannerDates($task);

    expect($dates)->toBe(['2026-01-10', '2026-01-12']);
});

it('falls back to start and end date columns when no options.dates exist', function (): void {
    $task = new Task();
    $task->options = [];
    $task->start_date = '2026-02-01';
    $task->end_date = '2026-02-05';

    $dates = invokeTaskPlannerDates($task);

    expect($dates)->toBe(['2026-02-01', '2026-02-05']);
});

it('returns null dates for an undated task', function (): void {
    $task = new Task();
    $task->options = [];

    expect(invokeTaskPlannerDates($task))->toBe([null, null]);
});

it('defaults the time window filter to upcoming', function (): void {
    expect((new CardsIndex())->filterDateRange)->toBe('upcoming');
});

it('binds the time window filter to the url as "when"', function (): void {
    $attrs = (new ReflectionClass(CardsIndex::class))
        ->getProperty('filterDateRange')
        ->getAttributes(\Livewire\Attributes\Url::class);

    expect($attrs)->not->toBeEmpty()
        ->and($attrs[0]->getArguments())->toMatchArray(['as' => 'when']);
});

it('treats a non-default time window as an active filter', function (): void {
    $component = new CardsIndex();

    expect($component->hasActiveFilters())->toBeFalse();

    $component->filterDateRange = 'past';

    expect($component->hasActiveFilters())->toBeTrue();
});

it('resets the time window filter back to upcoming when cleared', function (): void {
    $component = new CardsIndex();
    $component->filterDateRange = 'all';

    $component->clearFilters();

    expect($component->filterDateRange)->toBe('upcoming');
});

function invokeTaskPlannerDates(Task $task): array
{
    $component = new CardsIndex();
    $method = new ReflectionMethod(CardsIndex::class, 'taskPlannerDates');
    $method->setAccessible(true);

    return $method->invoke($component, $task);
}
