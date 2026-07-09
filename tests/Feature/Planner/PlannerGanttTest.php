<?php

use App\Livewire\Planner\CardsIndex;

it('maps gantt zoom levels to the expected pixels-per-day', function (string $zoom, int $expected): void {
    $component = new CardsIndex();
    $component->ganttZoom = $zoom;

    expect($component->ganttPxPerDay())->toBe($expected);
})->with([
    'day'   => ['day', 140],
    'week'  => ['week', 80],
    'month' => ['month', 32],
]);

it('falls back to the day zoom when ganttZoom is unrecognized', function (): void {
    $component = new CardsIndex();
    $component->ganttZoom = 'decade';

    expect($component->ganttPxPerDay())->toBe(140);
});

it('exposes the three gantt zoom levels as a constant', function (): void {
    expect(CardsIndex::GANTT_PX_PER_DAY)
        ->toHaveKeys(['day', 'week', 'month'])
        ->and(CardsIndex::GANTT_PX_PER_DAY['day'])->toBeGreaterThan(CardsIndex::GANTT_PX_PER_DAY['week'])
        ->and(CardsIndex::GANTT_PX_PER_DAY['week'])->toBeGreaterThan(CardsIndex::GANTT_PX_PER_DAY['month']);
});

it('declares viewMode and ganttZoom as URL-bound query string properties', function (): void {
    $reflection = new ReflectionClass(CardsIndex::class);

    $viewModeAttrs = $reflection->getProperty('viewMode')
        ->getAttributes(\Livewire\Attributes\Url::class);
    $zoomAttrs = $reflection->getProperty('ganttZoom')
        ->getAttributes(\Livewire\Attributes\Url::class);

    expect($viewModeAttrs)->not->toBeEmpty()
        ->and($zoomAttrs)->not->toBeEmpty()
        ->and($viewModeAttrs[0]->getArguments())->toMatchArray(['as' => 'view'])
        ->and($zoomAttrs[0]->getArguments())->toMatchArray(['as' => 'zoom']);
});

it('exposes the gantt computed methods', function (): void {
    $reflection = new ReflectionClass(CardsIndex::class);

    foreach (['ganttPxPerDay', 'ganttRows', 'ganttDependencyLinks', 'criticalPathTaskIds'] as $method) {
        expect($reflection->hasMethod($method))->toBeTrue("missing computed method: {$method}");

        $attrs = $reflection->getMethod($method)
            ->getAttributes(\Livewire\Attributes\Computed::class);

        expect($attrs)->not->toBeEmpty("{$method} should be annotated with #[Computed]");
    }
});

it('exposes the updateTaskDates action used by the gantt drag handlers', function (): void {
    $reflection = new ReflectionClass(CardsIndex::class);

    expect($reflection->hasMethod('updateTaskDates'))->toBeTrue();

    $method = $reflection->getMethod('updateTaskDates');
    $params = $method->getParameters();

    // 3 required + 2 optional (oldStart/oldEnd, passed by the drag handler so
    // an update can be reverted client-side if the server rejects it).
    expect($method->isPublic())->toBeTrue()
        ->and($params)->toHaveCount(5)
        ->and($params[0]->getName())->toBe('taskId')
        ->and($params[1]->getName())->toBe('startDate')
        ->and($params[2]->getName())->toBe('endDate')
        ->and($params[3]->isOptional())->toBeTrue()
        ->and($params[4]->isOptional())->toBeTrue();
});
